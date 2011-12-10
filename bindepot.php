<?php
class FileResult {
	function __construct($path) {
		$this->path = $path;
	}
}

class BinaryHandler {
	function store($path, $id, $file) {
		if(is_array($file)) {
			if(!move_uploaded_file($file['tmp_name'], "$path/$id")) {
				throw new Exception("FileStorage: Could not move uploaded file: {$file['tmp_name']}");
			}
		}
		else {
			$fh = fopen("$path/$id", 'wb') or die("Cannot open file: $id");
			fwrite($fh, $file);
			fclose($fh);
		}
	}

	function retrieve($path, $id) {
		return new FileResult("$path/$id");
	}
}

class ImageHandler extends BinaryHandler {
	function store($path, $id, $file) {
		parent:store($path, $id, $file);
	}

	function retrieve($path, $id, $mode) {
		return parent::retrieve($path, $id, $mode);
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
		$this->path = "$this->default_path/$store";
		$this->handler = $this->getHandler($store);
	}

	private function load_configuration($conf, $store) {
		$conf = simplexml_load_file($conf);

		$path = $conf->xpath('/bindepot/@default-path');
		$this->default_path = isset($path[0]) ? $path[0]: '';

		$type = $conf->xpath("/bindepot/store[@name='a$store']/@type");
		$this->type = isset($type[0]) ? $type[0]: 'binary';
	}

	private function getHandler($store) {
		switch($this->type) {
			case 'image':
				$handler = new ImageHandler();
				break;
			case 'binary':
			default:
				$handler = new BinaryHandler();
				break;
		}
		return $handler;
	}

	function store($id, $file) {
		if(@!file_exists($this->path))
			mkdir($this->path, 0777);
		$this->handler->store($this->path, $id, $file);
	}

	function retrieve($id, $format) {
		return $this->handler->retrieve($this->path, $id, '');
	}
}
?>
