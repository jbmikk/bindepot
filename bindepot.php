<?php
class FileElement {
	function __construct($path) {
		$this->path = $path;
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
		if(is_array($file)) {
			if(!move_uploaded_file($file['tmp_name'], "$this->path/$id")) {
				throw new Exception("FileStorage: Could not move uploaded file: {$file['tmp_name']}");
			}
		}
		else {
			$fh = fopen("$this->path/$id", 'wb') or die("Cannot open file: $id");
			fwrite($fh, $file);
			fclose($fh);
		}
	}

	function retrieve($id, $mode) {
		return new FileElement("$this->path/$id");
	}

	function find($expression) {
		$result = array();
		$dir = opendir($this->path);
		while($file = readdir($dir)) {
			if($file != "." && $file != "..")
				$result[] = $file;
		}
		return $result;
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
			$width = $format->xpath("@width");
			$width = (int)$width[0];
			$height = $format->xpath("@height");
			$height = (int)$height[0];
			$this->formats[] = array(
				'width' => $width,
				'height' => $height
			);
		}
	}

	function storeCopy($id, $file, $format) {
		$filepath = $file['tmp_name'];
		$new_w = $format['width'];
		$new_h = $format['height'];
		$info = getimagesize($filepath);
		$path = "$this->path_copy/$new_w-$new_h";
		if(@!file_exists($path))
			mkdir($path, 0777);
		switch($info[2]) {
			case IMAGETYPE_JPEG:
				$image = imagecreatefromjpeg($filepath);
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
		imagejpeg($new_image, "$path/$id");
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

	function find($expression) {
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
		$this->handler->store($id, $file);
	}

	function retrieve($id, $mode = null) {
		return $this->handler->retrieve($id, $mode);
	}

	function find($expression = null) {
		return $this->handler->find($expression);
	}
}
?>
