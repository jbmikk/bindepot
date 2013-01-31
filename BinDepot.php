<?php
namespace BinDepot;

function xml_get_attr($xml, $attr, $default = null) {
	$value = $xml->xpath($attr);
	if(count($value) > 0)
		$value = $value[0];
	else
		$value = $default;
	return $value;
}

class DiskFile {
	function __construct($path, $id) {
		$this->path = $path;
		$this->id = $id;
	}

	function copyTo($path) {
		if (!copy($this->path, $path)) {
			throw new \Exception("FileStorage: could not copy file: {$this->path} to $path");
		}
	}
}

class UploadFile {
	function __construct($desc) {
		$this->path = $desc['tmp_name'];
	}

	function copyTo($path) {
		if(!move_uploaded_file($this->path, $path)) {
			throw new \Exception("FileStorage: Could not move uploaded file: {$this->path}");
		}
	}
}

class MemFile {
	function __construct($data) {
		$this->data = $data;
	}

	function copyTo($path) {
		$fh = fopen($path, 'wb') or die("Cannot open file: $path");
		fwrite($fh, $this->data);
		fclose($fh);
	}
}

function buildFile($source, $id) {
	if(is_string($source)) {
		return new DiskFile($source, $id);
	} else if(is_array($source)) {
		return new UploadFile($source);
	} else {
		return new MemFile($source);
	}
}

class BinaryHandler {
	protected $conf;
	protected $path;

	function __construct($path, $conf) {
		$this->conf = $conf;
		$this->path = $path;
	}

	function checkDir($path, $id) {
		$pos = strrpos($id, "/");
		if($pos !== false) {
			$p = "$path/".substr($id, 0, $pos);
			if(@!file_exists($p) || !is_dir($p))
				mkdir($p, 0777, true);
		}
	}

	function store($id, $file) {
		$this->checkDir($this->path, $id);
		$file->copyTo("$this->path/$id");
	}

	function retrieve($id, $mode) {
		return buildFile("$this->path/$id", $id);
	}

	function find($expression) {
		$result = array();
		$dir = opendir($this->path);
		while($id = readdir($dir)) {
			if($id != "." && $id!= "..")
				$result[] = buildFile("$this->path/$id", $id);
		}
		return $result;
	}

	function reconfig($mode) {
	}
}

class ImageHandler extends BinaryHandler {
	function __construct($path, $conf) {
		$this->conf = $conf;
		$this->path = "$path/original";
		$this->path_copy = $path;
		if(@!file_exists($this->path))
			mkdir($this->path, 0777);

		$this->formats = array();
		$formats = $this->conf->xpath('format');
		foreach($formats as $format) {
			$this->formats[] = array(
				'width' => (int)xml_get_attr($format, "@width", 0),
				'height' => (int)xml_get_attr($format, "@height", 0),
				'max-width' => (int)xml_get_attr($format, "@max-width", 0),
				'max-height' => (int)xml_get_attr($format, "@max-height", 0),
				'quality' => (int)xml_get_attr($format, "@quality", 90),
				'type' => xml_get_attr($format, "@type", 'jpg'),
				'filter' => xml_get_attr($format, "@filter", ''),
				'name' => xml_get_attr($format, "@name", '')
			);
		}
	}

	function storeCopy($id, $file, $format) {
		$new_w = $format['width'];
		$new_h = $format['height'];
		$max_w = $format['max-width'];
		$max_h = $format['max-height'];
		$info = getimagesize($file->path);
		$original = "jpg";
		if($format['name'] != '') {
			$name = $format['name'];
		} else {
			$name = "$new_w-$new_h";
			if ($max_w != 0 || $max_h != 0) {
				$name = $name."-max$max_w-max$max_h";
			}
		}
		$path = "$this->path_copy/$name";
		if(@!file_exists($path))
			mkdir($path, 0777);
		switch($info[2]) {
			case IMAGETYPE_JPEG:
				$image = imagecreatefromjpeg($file->path);
				$original = "jpg";
				break;
			case IMAGETYPE_PNG:
				$image = imagecreatefrompng($file->path);
				$original = "png";
				break;
			case IMAGETYPE_GIF:
				$image = imagecreatefromgif($file->path);
				$original = "gif";
				break;
			default:
				throw new \Exception("FileStorage: image format not supported, IMAGETYPE: {$info[2]}");
				break;
		}
		$old_w = imagesx($image);
		$old_h = imagesy($image);

		//Autocomplete pending dimensions for new image
		if($new_w == 0 && $new_h != 0) {
			$ratio = $old_h/$new_h;
			$new_w = $old_w/$ratio;
		} elseif($new_w != 0 && $new_h == 0) {
			$ratio = $old_w/$new_w;
			$new_h = $old_h/$ratio;
		} elseif($new_w == 0 && $new_h == 0) {
			$new_w = $old_w;
			$new_h = $old_h;
		}

		//Cap dimensions according to max definitions
		if($max_w > 0 && $new_w > $max_w) {
			$ratio = $new_w/$max_w;
			$new_h = $new_h/$ratio;
			$new_w = $max_w;
		}
		if($max_h > 0 && $new_h > $max_h) {
			$ratio = $new_h/$max_h;
			$new_w = $new_w/$ratio;
			$new_h = $max_h;
		}

		//Create image with final dimensions
		$new_image = imagecreatetruecolor($new_w, $new_h);

		$visible_x = 0;
		$visible_y = 0;
		$visible_w = $old_w;
		$visible_h = $old_h;

		//If aspect ratio remains the same between images, nothing is clipped
		if($old_w/$old_h > $new_w/$new_h) {
			//clip horizontally
			$ratio = $old_h/$new_h;
			$visible_w = $new_w * $ratio;
			$visible_x = ($old_w - $visible_w)/2;
		} else {
			//clip vertically
			$ratio = $old_w/$new_w;
			$visible_h = $new_h * $ratio;
			$visible_y = ($old_h - $visible_h)/2;
		}

		//Resolve format
		if($format['type'] == 'original')
			$format['type'] = $original;
		
		//Set transparency for png
		if($format['type'] == 'png') {
			imagealphablending($new_image, false);
			imagesavealpha($new_image, true);
			$transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
			imagefilledrectangle($new_image, 0, 0, $new_w, $new_h, $transparent);
		}
		imagecopyresampled($new_image, $image, 0, 0, $visible_x, $visible_y, $new_w, $new_h, $visible_w, $visible_h);

		switch($format['filter']) {
		case "grayscale":
			imagefilter($new_image, IMG_FILTER_GRAYSCALE);
			break;
		case "negate":
			imagefilter($new_image, IMG_FILTER_NEGATE);
			break;
		case "edgedetect":
			imagefilter($new_image, IMG_FILTER_EDGEDETECT);
			break;
		case "emboss":
			imagefilter($new_image, IMG_FILTER_EMBOSS);
			break;
		case "gaussian_blur":
			imagefilter($new_image, IMG_FILTER_GAUSSIAN_BLUR);
			break;
		case "selective_blur":
			imagefilter($new_image, IMG_FILTER_SELECTIVE_BLUR);
			break;
		case "mean_removal":
			imagefilter($new_image, IMG_FILTER_MEAN_REMOVAL);
			break;
		}

		$this->checkDir($path, $id);

		switch($format['type']) {
		case "png":
			imagepng($new_image, "$path/$id", 9);
			break;
		case "gif":
			imagegif($new_image, "$path/$id");
			break;
		default:
		case "jpg":
			imagejpeg($new_image, "$path/$id", $format['quality']);
			break;
		}
		imagedestroy($new_image);
	}

	function store($id, $file) {
		foreach($this->formats as $format) {
			$this->storeCopy($id, $file, $format);
		}
		parent::store($id, $file);
	}

	function retrieve($id, $mode) {
		return parent::retrieve($id, $mode);
	}

	function getPath($id, $mode) {
		if($mode == null)
			$mode = 'default';
		return "{$this->path_copy}/$mode/$id";
	}

	function reconfig($mode) {
		$files = $this->find();
		foreach($files as $file) {
			foreach($this->formats as $format) {
				$this->storeCopy($file->id, $file, $format);
			}
		}
		return parent::reconfig($mode);
	}

	function find($expression = null) {
		return parent::find($expression);
	}
}

class BinDepot{

	function __construct($store, $conf = 'bindepot.xml') {
		$this->store = $store;
		$this->conf = $conf;
		$this->initialized = false;
	}

	private function load_configuration() {
		$conf = simplexml_load_file($this->conf);
		$store = $this->store;

		$path = $conf->xpath('/bindepot/@default-path');
		$this->default_path = isset($path[0]) ? $path[0]: '';
		$this->path = "$this->default_path/$store";

		$type = $conf->xpath("/bindepot/store[@name='$store']/@type");
		$this->type = isset($type[0]) ? $type[0]: 'binary';

		$storeConf = $conf->xpath("/bindepot/store[@name='$store']");
		$this->storeConf = isset($storeConf[0])? $storeConf[0]: null;
	}

	private function getHandler($store) {
		switch($this->type) {
			case 'image':
				$handler = new ImageHandler($this->path, $this->storeConf);
				break;
			case 'binary':
			default:
				$handler = new BinaryHandler($this->path, $this->storeConf);
				break;
		}
		return $handler;
	}

	private function init() {
		if(!$this->initialized) {
			$this->load_configuration();
			if(@!file_exists($this->default_path)) {
				throw new Exception("FileStorage: default storage directory does not exist: $this->default_path");
			}
			if(@!is_writable($this->default_path)) {
				throw new Exception("FileStorage: default storage directory is not writable: $this->default_path");
			}
			if(@!file_exists($this->path))
				mkdir($this->path, 0777);

			$this->handler = $this->getHandler($this->store);
			$this->initialized = true;
		}
	}

	function store($id, $file) {
		$this->init();
		$file = buildFile($file, $id);
		$this->handler->store($id, $file);
	}

	function retrieve($id, $mode = null) {
		$this->init();
		return $this->handler->retrieve($id, $mode);
	}

	function getPath($id, $mode = null) {
		$this->init();
		return $this->handler->getPath($id, $mode);
	}

	function reconfig($mode = null) {
		$this->init();
		return $this->handler->reconfig($mode);
	}

	function find($expression = null) {
		$this->init();
		return $this->handler->find($expression);
	}
}

?>
