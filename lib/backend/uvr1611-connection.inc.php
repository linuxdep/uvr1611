<?php
/**
 * Uvr1611 Connection (Singleton)
 *
 * Provides access to the bootloader for stored datasets and actuell values
 *
 * @copyright  Copyright (c) Bertram Winter bertram.winter@gmail.com
 * @license    GPLv3 License
 */
include_once("lib/config.inc.php");
include_once("lib/backend/parser.inc.php");

class Uvr1611
{
	/**
     * Constants for the UVR Communication
	 */
	const CAN_MODE = "\xDC";
	const DL_MODE = "\xA8";
	const DL2_MODE = "\xD1";
	const GET_MODE = "\x81";
	const GET_HEADER = "\xAA";
	const GET_LATEST = 0xAB;
	const READ_DATA = 0xAC;
	const END_READ = "\xAD";
	const RESET_DATA = "\xAF";
	const WAIT_TIME = 0xBA;
	const MAX_RETRYS = 4;
	const DATASET_SIZE = 61;
	const LATEST_SIZE = 56;
	
	
	/**
	 *  Singleton Interface
	 */
	public static $instance;
	public static function getInstance()
	{
		if (null == self::$instance) {
			self::$instance = new self;
		}
		return self::$instance;
	}
	
	/**
	 * Privates
	 */
	private $config;
	private $sock;
	private $count=0;
	private $address=0;
	private $mode;
	private $addressInc = 64;
	private $actualSize = 57;
	private $fetchSize = 65;
	private $canFrames = 1;

	/**
	 * Constructor
	 */
	private function __construct()
	{
		$this->config = Config::getInstance()->uvr1611;
		$this->checkMode();
	}

	/**
	 * Get the actuell values from the bootloader
	 * @throws Exception
	 * @return Parser
	 */
	public function getLatest()
	{
		$this->getCount();
		create_pid();
		// build command
		$cmd = pack("C2",self::GET_LATEST,1);
		// try 4 times to get values
		for($i=0; $i<self::MAX_RETRYS; $i++) {
			$data = $this->query($cmd, $this->actualSize);
			
			if($this->checksum($data)) {
				$binary = unpack("C*",$data);
				if($binary[1] == self::WAIT_TIME) {
					// wait some seconds for data
					sleep($binary[2]);
				}
				else {	
					close_pid();
					return $this->splitLatest($data);
				}
			}
		}
		close_pid();
		throw new Exception("Could not get latest data!");
	}
	
	/**
	 * End read and reset memory on the bootloader
	 */
	public function endRead()
	{
		create_pid();
		// send end read command
		if($this->query(self::END_READ, 1) != self::END_READ) {
			throw new Exception("End read command failed.");
		}
		// reset data if configured
		if($this->config->reset) {
			if($this->query(self::RESET_DATA, 1) != self::RESET_DATA) {
				throw new Exception("Could not reset memory.");
			}
		}
		$this->count = 0;
		$this->address = 0;
		close_pid();
	}
	
	
	/**
	 * Fetch datasets from bootloader memory
	 * @throws Exception Checksum error
	 * @return Parser
	 */
	public function fetchData()
	{
		if($this->count > 0) {
			create_pid();
			
			// build address for bootloader
			$address1 = $this->address & 0xFF;
			$address2 = ($this->address & 0x7F00)>>7;
			$address3 = ($this->address & 0xFF8000)>>15;
			
			// build command
			$cmd = pack("C6", self::READ_DATA, $address1, $address2, $address3, 1,
							  self::READ_DATA + 1 + $address1 + $address2 + $address3);
			
			$data = $this->query($cmd, $this->fetchSize);
			
			if($this->checksum($data)) {
				// increment address
				$this->address += $this->addressInc;
				$this->count--;
				close_pid();
				return $this->splitDatasets($data);
			}
			close_pid();
			throw new Exception("Could not get data!");
		}
	}
	
	/**
	 * Get the number of datasets in the bootloader memory
	 * @return number
	 */
	public function getCount()
	{
		if($this->count == 0) {
			create_pid();
			$data = $this->query(self::GET_HEADER, 21);
			
			if($this->checksum($data)) {
				switch($this->mode) {
					case self::CAN_MODE:
						$binary = unpack("C5/CnumberOfFrames/C*",$data);
						$binary = unpack("Ctype/Cversion/C3timestamp/CnumberOfFrames/C".
										 $binary["numberOfFrames"].
										 "/C3startaddress/C3endaddress/Cchecksum", $data);
						$this->addressInc = 64 * $binary["numberOfFrames"];
						$this->canFrames = $binary["numberOfFrames"];
						$this->actualSize = 57;
						$this->fetchSize = 4+61*$this->canFrames;
						break;
					case self::DL_MODE:
						$binary = unpack("C5/Cdevice1/C3startaddress/C3endaddress/Cchecksum",
										 $data);
						$this->addressInc = 64;
						$this->actualSize = 57;
						$this->fetchSize = 65;
						break;
					case self::DL2_MODE:
						$binary = unpack("C5/Cdevice1/Cdevice2/C3startaddress/C3endaddress/Cchecksum",
										 $data);
						$this->addressInc = 128;
						$this->actualSize = 113;
						$this->fetchSize = 126;
						break;
				}
				
				// check if address is valid (!= 0xFFFFFF)
				if($binary["startaddress3"] != 0xFF ||
				   $binary["startaddress2"] != 0xFF ||
				   $binary["startaddress1"] != 0xFF ||
				   $binary["endaddress3"] != 0xFF ||
				   $binary["endaddress2"] != 0xFF ||
				   $binary["endaddress1"] != 0xFF)
				{
					// fix addresses
					$startaddress = ($binary["startaddress3"] << 15)
								  + ($binary["startaddress2"] << 7)
								  + $binary["startaddress1"];
					$endaddress = ($binary["endaddress3"] << 15)
							    + ($binary["endaddress2"] << 7)
							    + $binary["endaddress1"];
					// calculate count
					$this->count = (($endaddress - $startaddress)
								 / $this->addressInc) + 1;
					$this->address = $startaddress;
				}
			}
			close_pid();
		}
		return $this->count;
	}
	
	/**
	 * Check if Bootloader Mode is supported
	 * @throws Exception Mode not supported
	 */
	private function checkMode()
	{
		$this->mode = $this->query(self::GET_MODE, 1);

		switch($this->mode) {
			case self::CAN_MODE:
			case self::DL_MODE:
			case self::DL2_MODE:
				return;
		}
		throw new Exception('BL-Net mode is not supported!');
	}
	
	/**
	 * Connect via TCP to the bootloader
	 */
	private function connect()
	{
		$this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		socket_connect($this->sock,
					   $this->config->address,
					   $this->config->port);
	}
	
	/**
	 * Disconnect from the bootloader
	 */
	private function disconnect()
	{
		socket_close($this->sock);
	}
	
	/**
	 * Verify the checksum
	 * @param string $data Binary string to check
	 * @return boolean
	 */
	private function checksum($data)
	{
		$binary = unpack("C*",$data);
		$sum = 0;
		// pop checksum from string
		$checksum = array_pop($binary);
		// sum up all bytes
		foreach($binary as $byte) {
			$sum += $byte;	
		}
		// verify the checksum
		if(($sum % 256) == $checksum) {
			return true;
		}
		return false;
	}
	
	/**
	 * Send a command to the bootloader and wait for the response
	 * if response is less then 32 bytes long return immediately
	 * @param string $cmd
	 * @param int $length
	 * @throws Exception
	 * @return string Binary
	 */
	private function query($cmd, $length)
	{
		$this->connect();
		// send command
		if(strlen($cmd) == socket_write($this->sock, $cmd, strlen($cmd))) {
			$data = "";
			// get response until length or less 32 bytes
			do {
				$return = socket_read($this->sock, $length, PHP_BINARY_READ);
				$data .= $return;
			}
			while(strlen($return)>32 && strlen($data) < $length);
			
			$this->disconnect();
			return $data;
		}

		$this->disconnect();
		throw new Exception('Error while querying command!\nCommand: '.bin2hex($cmd));
	}
	
	/**
	 * Split a binary string in datasets and parse it (Datasets values)
	 * @param string $data
	 * @return Parser
	 */
	private function splitDatasets($data)
	{
		$frames = array();
		switch($this->mode) {
			case self::CAN_MODE:
				for($i=0;$i<$this->canFrames;$i++) {
					$frames["frame".($i+1)] = new Parser(substr($data, 3+self::DATASET_SIZE*$i, self::DATASET_SIZE));
				}
				break;
			case self::DL_MODE:
				$frames["frame1"] = new Parser(substr($data, 0, self::DATASET_SIZE));
				break;
			case self::DL2_MODE:
				$frames["frame1"] = new Parser(substr($data, 0, self::DATASET_SIZE));
				$frames["frame2"] = new Parser(substr($data, 3+self::DATASET_SIZE, self::DATASET_SIZE));
				break;
		}
		return $frames;
	}
	
	/**
	 * Split a binary string in datasets and parse it (Actuell values)
	 * @param string $data
	 * @return Parser
	 */
	private function splitLatest($data)
	{
		$frames = array();
		switch($this->mode) {
			case self::CAN_MODE:
				for($i=0;$i<$this->canFrames;$i++) {
					$frames["frame".($i+1)] = new Parser(substr($data, 1+self::LATEST_SIZE*$i, self::LATEST_SIZE));
				}
				break;
			case self::DL_MODE:
				$frames["frame1"] = new Parser(substr($data, 1, self::LATEST_SIZE));
				break;
			case self::DL2_MODE:
				$frames["frame1"] = new Parser(substr($data, 1, self::LATEST_SIZE));
				$frames["frame2"] = new Parser(substr($data,1+self::LATEST_SIZE, self::LATEST_SIZE));
				break;
		}
	
		return $frames;
	}
}

/**
 * Create a PID file
 * @throws Exception
 */
function create_pid()
{
	$path = '/tmp/uvr1611-logger.pid';
	if(file_exists($path)) {
		// if PID is older than an hour remove it
		if(time() > (filemtime($path) + 3600)) {
			$pid = file_get_contents($path);
			exec("kill $pid");
		}
		else {
			throw new Exception("Another process is accessing the bl-net!");
		}

	}
	file_put_contents($path, getmypid());
}

/**
 * Remove the PID file
 */
function close_pid()
{
	unlink('/tmp/uvr1611-logger.pid');
}