<?php
class FileResult {
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
		return new FileResult("$this->path/$id");
	}
}

class ImageHandler extends BinaryHandler {
	function __construct($path, $conf) {
		$this->conf = $conf;
		$this->path = $path;
	}

	function store($id, $file) {
		parent::store($id, $file);
	}

	function retrieve($id, $mode) {
		return parent::retrieve($id, $mode);
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

	function retrieve($id, $format) {
		return $this->handler->retrieve($id, '');
	}
}
?>
