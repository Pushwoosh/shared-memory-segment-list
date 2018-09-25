<?php

namespace Pushwoosh\SharedMemorySegmentList;

/**
 * Class Segment
 * Simply wraps shmop_* functions
 */
class Segment {
	/** @var  int */
	private $key;

	/** @var  string */
	private $flags;

	/** @var  int */
	private $mode;

	/** @var  int */
	private $size;

	private $shm;

	/**
	 * SharedMemorySegment constructor.
	 * @param int $key
	 * @param string $flags
	 * @param int $mode
	 * @param int $size
	 */
	public function __construct(int $key, string $flags, int $mode, int $size) {
		$this->key = $key;
		$this->flags = $flags;
		$this->mode = $mode;
		$this->size = $size;
	}

	public function __destruct() {
		if (!$this->isOpened()) {
			return;
		}

		$this->close();
	}

	/**
	 * @return bool
	 */
	public function open(): bool {
		$this->shm = @\shmop_open($this->key, $this->flags, $this->mode, $this->size);

		if ($this->shm === false) {
			$this->shm = null;
			return false;
		}

		if ($this->size == 0) {
			$this->size = \shmop_size($this->shm);
		}

		return true;
	}

	/**
	 * @return bool
	 */
	public function isOpened(): bool {
		return (bool)$this->shm;
	}

	/**
	 * Close, but do not delete shared segment
	 * @throws \RuntimeException
	 */
	public function close() {
		if (!$this->isOpened()) {
			throw new \RuntimeException("Shared memory segment is not opened");
		}
		\shmop_close($this->shm);
		$this->shm = null;
	}

	/**
	 * Delete shared segment
	 * @throws \RuntimeException
	 */
	public function delete() {
		if (!$this->isOpened()) {
			throw new \RuntimeException("Cannot delete closed shared segment");
		}
		$ret = \shmop_delete($this->shm);
		$this->shm = null;
		return $ret;
	}

	/**
	 * @return int
	 * @throws \RuntimeException
	 */
	public function size(): int {
		if (!$this->isOpened()) {
			throw new \RuntimeException("Shared memory segment is not opened");
		}

		return \shmop_size($this->shm);
	}

	/**
	 * Read data from segment. Returns string on success or false on failure
	 * @param int $offset
	 * @param int $length
	 * @return string|false
	 * @throws \RuntimeException, \OutOfBoundsException
	 */
	public function read(int $offset, int $length) {
		if (!$this->isOpened()) {
			throw new \RuntimeException("Shared memory segment is not opened");
		}

		if ($offset + $length > $this->size) {
			throw new \OutOfBoundsException("Reading after the end of segment");
		}

		$ret = \shmop_read($this->shm, $offset, $length);

		return $ret;
	}

	/**
	 * Write data to shared segment. Returns the size of the written data or false on failure
	 * @param int $offset
	 * @param string $data
	 * @return bool
	 * @throws \RuntimeException
	 */
	public function write(int $offset, string $data) {
		if (!$this->isOpened()) {
			throw new \RuntimeException("Shared memory segment is not opened");
		}

		// do not use mb_strlen, we need bytes, not characters
		$length = \strlen($data);

		//printf("[%08X] writing %3d bytes @ %d\n", $this->key, $length, $offset);
		if ($offset + $length > $this->size) {
			throw new \OutOfBoundsException("Writing after the end of segment");
		}

		return \shmop_write($this->shm, $data, $offset);
	}
}
