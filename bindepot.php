<?php
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
			throw new Exception("FileStorage: could not copy file: {$this->path} to $path");
		}
	}
}

class UploadFile {
	function __construct($desc) {
		$this->path = $desc['tmp_name'];
	}

	function copyTo($path) {
		if(!move_uploaded_file($this->path, $path)) {
			throw new Exception("FileStorage: Could not move uploaded file: {$this->path}");
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

	function store($id, $file) {
		$file->copyTo("$this->path/$id");
	}

	function retrieve($id, $mode) {
		return buildFile($this->path, $id);
	}

	function find($expression) {
		$result = array();
		$dir = opendir($this->path);
		while($id = readdir($dir)) {
			if($id != "." && $id!= "..")
				
				$result[] = buildFile($this->path, $id);
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
				'width' => (int)xml_get_attr($format, "@width"),
				'height' => (int)xml_get_attr($format, "@height"),
				'quality' => (int)xml_get_attr($format, "@quality", 90)
			);
		}
	}

	function storeCopy($id, $file, $format) {
		$new_w = $format['width'];
		$new_h = $format['height'];
		$info = getimagesize($file->path);
		$path = "$this->path_copy/$new_w-$new_h";
		if(@!file_exists($path))
			mkdir($path, 0777);
		switch($info[2]) {
			case IMAGETYPE_JPEG:
				$image = imagecreatefromjpeg($file->path);
				$old_w = imagesx($image);
				$old_h = imagesy($image);
				break;
			default:
				throw new Exception("FileStorage: image format not supported, IMAGETYPE: {$info[2]}");
				break;
		}
		$new_image = imagecreatetruecolor($new_w, $new_h);

		$visible_x = 0;
		$visible_y = 0;
		$visible_w = $old_w;
		$visible_h = $old_h;

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

		imagecopyresampled($new_image, $image, 0, 0, $visible_x, $visible_y, $new_w, $new_h, $visible_w, $visible_h);
		imagejpeg($new_image, "$path/$id", $format['quality']);
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
		$this->load_configuration($conf, $store);
		if(@!file_exists($this->default_path)) {
			throw new Exception("FileStorage: default storage directory does not exist: $this->default_path");
		}
		if(@!is_writable($this->default_path)) {
			throw new Exception("FileStorage: default storage directory is not writable: $this->default_path");
		}
		if(@!file_exists($this->path))
			mkdir($this->path, 0777);

		$this->handler = $this->getHandler($store);
	}

	private function load_configuration($conf, $store) {
		$conf = simplexml_load_file($conf);

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

	function store($id, $file) {
		$file = buildFile($file, $id);
		$this->handler->store($id, $file);
	}

	function retrieve($id, $mode = null) {
		return $this->handler->retrieve($id, $mode);
	}

	function reconfig($mode = null) {
		return $this->handler->reconfig($mode);
	}

	function find($expression = null) {
		return $this->handler->find($expression);
	}
}

?>
