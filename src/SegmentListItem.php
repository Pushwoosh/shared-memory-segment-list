<?php

namespace Pushwoosh\SharedMemorySegmentList;

class SegmentListItem {
	// Each item takes this much bytes in memory
	const SIZE =
			4 + // key
			4 + // size
			4 + // data size
			4   // access timestamp
		;
	/**
	 * @var int
	 */
	private $key;

	/**
	 * @var int
	 */
	private $segmentSize;

	/**
	 * that much of bytes are really used
	 * @var int
	 */
	private $dataSize;

	/**
	 * @var int
	 */
	private $timestamp;

	/**
	 * SharedMemorySegmentListItem constructor.
	 * @param int $key
	 * @param int $size
	 * @param int $dataSize
	 * @param int $timestamp
	 */
	public function __construct(int $key, int $size, int $dataSize, int $timestamp) {
		$this->key = $key;
		$this->segmentSize = $size;
		$this->dataSize = $dataSize;
		$this->timestamp = $timestamp;
	}

	/**
	 * @return int
	 */
	public function getKey(): int {
		return $this->key;
	}

	/**
	 * @return int
	 */
	public function getSegmentSize(): int {
		return $this->segmentSize;
	}

	public function setSegmentSize(int $size) {
		$this->segmentSize = $size;
	}

	/**
	 * @return int
	 */
	public function getDataSize(): int {
		return $this->dataSize;
	}

	/**
	 * @param int $size
	 */
	public function setDataSize(int $size) {
		$this->dataSize = $size;
	}

	/**
	 * @return int
	 */
	public function getTimestamp(): int {
		return $this->timestamp;
	}

	public function touchTimestamp() {
		if ($this->key) {
			$this->timestamp = time();
		}
	}
}
