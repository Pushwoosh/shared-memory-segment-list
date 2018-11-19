<?php

namespace Pushwoosh\SharedMemorySegmentList;

/**
 * Segment list layout
 * +----------+--------+--------+------------------+
 * |   offset | length |   type |             data |
 * +- Header -+--------+--------+------------------+
 * |     0x00 |      4 | uint32 |      list length |
 * +- Segment list item ---------------------------+
 * |     0x04 |      4 | uint32 |      segment key |
 * |     0x08 |      4 | uint32 |     segment size |
 * |     0x0C |      4 | uint32 |        data size |
 * |     0x10 |      4 | uint32 | access timestamp |
 * | ... more items ...                            |
 * +----------+--------+--------+------------------+
 */
class SegmentList {
	const HEADER_SIZE = 4;

	/** @var bool  */
	private $loaded = false;

	/**
	 * Maximum number of elements in list.
	 * It's possible to create dynamic sized list, but it adds unnecessary complexity
	 * We always should be able to set maximum number of elements
	 * @var int
	 */
	private $capacity;

	/** @var int */
	private $segmentId;

	private $itemsSegmentStartId;

	/**
	 * @var SegmentListItem[]
	 */
	private $items = [];

	public function __construct(int $key) {
		$this->segmentId = $key;
		$this->itemsSegmentStartId = $key + 1;
	}

	/**
	 * Initialize memory for a list. Function will fail if list is initialized already
	 * @var $capacity int
	 * @throws \RuntimeException
	 */
	public function init(int $capacity) {
		if ($this->loaded) {
			throw new \RuntimeException("Cannot init loaded list");
		}

		$wantSize = $this->getListSizeInBytes($capacity);

		// Try to *create* new segment
		$segment = new Segment($this->segmentId, "n", 0664, $wantSize);
		if (!$segment->open()) {
			throw new \RuntimeException(sprintf("Cannot create list header shared segment"));
		}

		$segment->close();

		$this->capacity = $capacity;

		// fill list with empty items
		for ($i = 0; $i < $this->capacity; $i++) {
			$item = SegmentListItemFactory::newEmpty();
			$this->items[$i] = $item;
		}
		$this->loaded = true;

		try {
			$this->write();
		}
		catch (\Exception $ex) {
			throw new \RuntimeException("Cannot init shared memory segment list", 0, $ex);
		}
	}


	/**
	 * Free list structure
	 */
	public function destroy() {
		if (!$this->loaded) {
			$this->read();
		}

		for ($i = 0; $i < $this->capacity; $i++) {
			$item = $this->items[$i];
			if ($item->getKey() !== 0) {
				$this->releaseSegment($i);
			}
		}

		$segment = new Segment($this->segmentId, "w", 0, 0);
		if (!$segment->open()) {
			throw new \RuntimeException(sprintf("Cannot open shared memory segment list"));
		}

		$segment->delete();
	}

	/**
	 * Reads list from shared memory
	 * Must return false on error or if the list is not allocated yet
	 * @throws \RuntimeException on any shared memory operation errors
	 */
	public function read() {
		// Create read only segment
		$segment = new Segment($this->segmentId, "a", 0, 0);
		// Following line will return false if memory was not allocated
		if (!$segment->open()) {
			// In PHP it's not possible to separate cases when segment does not exist and any other error
			throw new SegmentListNotInitializedException();
		}

		// read header
		try {
			$header = unpack('Ncapacity', $segment->read(0, 4));
		}
		catch (\Exception $ex) {
			throw new \RuntimeException("Cannot read shared memory segment list header", 0, $ex);
		}
		$this->capacity = $header['capacity'];

		// validate size
		$size = $segment->size();
		$wantSize = $this->getListSizeInBytes($this->capacity);
		if ($size != $wantSize) {
			$segment->close();
			throw new \RuntimeException(sprintf("Shared memory segment size is %d, must be %d to store list(len=%d)", $size, $wantSize, $this->capacity));
		}

		// read list items
		for ($i = 0; $i < $this->capacity; $i++) {
			$itemOffset = self::HEADER_SIZE + $i * SegmentListItem::SIZE;
			$binaryItem = $segment->read($itemOffset, SegmentListItem::SIZE);
			$item = SegmentListItemFactory::decode($binaryItem);
			$this->items[$i] = $item;
		}

		$this->loaded = true;
	}

	/**
	 * @return SegmentListItem[]
	 */
	public function getItems() {
		if (!$this->loaded) {
			$this->read();
		}

		$ret = [];
		for ($i = 0; $i < $this->capacity; $i++) {
			$item = $this->items[$i];
			if ($item->getKey() !== 0) {
				$ret[$i] = clone $item;
			}
		}

		return $ret;
	}

	/**
	 * Save list to shared memory
	 * @throws \RuntimeException
	 */
	public function write() {
		// nothing to write
		if (!$this->loaded) {
			return;
		}

		$segment = new Segment($this->segmentId, "w", 0, 0);
		if (!$segment->open()) {
			throw new \RuntimeException(sprintf("Cannot open list header shared segment"));
		}

		$size = $segment->size();
		$wantSize = $this->getListSizeInBytes($this->capacity);
		if ($size != $wantSize) {
			throw new \RuntimeException(sprintf("Shared memory segment list size is %d, must be %d to store list(len=%d)", $size, $wantSize, $this->capacity));
		}

		try {
			$offset = 0;
			// write header
			$segment->write($offset, pack('N', $this->capacity));
			$offset += self::HEADER_SIZE;

			// write items
			for ($i = 0; $i < $this->capacity; $i++) {
				$item = $this->items[$i];
				$binary = SegmentListItemFactory::encode($item);
				$segment->write($offset, $binary);
				$offset += SegmentListItem::SIZE;
			}
		}
		catch (\Exception $ex) {
			throw new \RuntimeException("Cannot save shared memory segment list", 0, $ex);
		}
		finally {
			$segment->close();
		}
	}

	/**
	 * Allocates new shared memory segment and returns id to access it
	 * @return int
	 * @throws \OutOfBoundsException if all segments are occupied
	 */
	public function allocateSegment(): int {
		if (!$this->loaded) {
			$this->read();
		}

		for ($i = 0; $i < $this->capacity; $i++) {
			if ($this->items[$i]->getKey() === 0) {
				// try to use free segment id
				// on fail just skip it and try next
				// this could happen if segment key is already occupied
				$key = $this->getSegmentKey($i);
				$segmentSize = $this->calculateSegmentSize(0);
				$segment = new Segment($key, "n", 0664, $segmentSize);
				if (!$segment->open()) {
					continue;
				}
				$segment->close();
				$this->items[$i] = new SegmentListItem($key, $segmentSize, 0, time());
				$this->write();
				return $i;
			}
		}

		throw new \OutOfBoundsException();
	}

	/**
	 * Free shared segment memory, making it available for future use
	 * @param $id
	 * @throws \OutOfBoundsException, \RuntimeException
	 */
	public function releaseSegment($id) {
		if (!$this->loaded) {
			$this->read();
		}

		if ($id < 0 || $id > $this->capacity) {
			throw new \OutOfBoundsException(sprintf("Requested to free segment %d. Total segments: %d", $id, $this->capacity));
		}

		$item = $this->items[$id];

		if (!$item) {
			throw new \RuntimeException(sprintf("Item %d not found", $id));
		}

		if ($item->getKey() === 0) {
			return;
		}

		$segment = new Segment($item->getKey(), "w", 0, 0);
		if (!$segment->open()) {
			throw new \RuntimeException(sprintf("Cannot open segment 0x%08X", $item->getKey()));
		}

		$segment->delete();

		$this->items[$id] = SegmentListItemFactory::newEmpty();
		$this->write();
	}

	/**
	 * Returns contents of given segment
	 * @param int $id
	 * @return null|string
	 */
	public function readSegment(int $id) {
		if (!$this->loaded) {
			$this->read();
		}

		if ($id < 0 || $id > $this->capacity) {
			throw new \OutOfBoundsException("List item is out of bounds");
		}

		$item = $this->items[$id];
		if ($item->getKey() === 0) {
			return null;
		}

		// shmop_read returns whole segment if $length is 0
		if ($item->getDataSize() == 0) {
			return '';
		}

		$segment = new Segment($item->getKey(), "a", 0, 0);
		if (!$segment->open()) {
			return null;
		}

		$ret = $segment->read(0, $item->getDataSize());
		if ($ret === false) {
			$ret = null;
		}

		$segment->close();

		return $ret;
	}

	/**
	 * @param int $id
	 * @param string $data
	 */
	public function writeToSegment(int $id, string $data) {
		if (!$this->loaded) {
			$this->read();
		}

		if ($id < 0 || $id > $this->capacity) {
			throw new \OutOfBoundsException("List item is out of bounds");
		}

		// need size in bytes here, not in characters
		$length = \strlen($data);

		$item = $this->items[$id];
		if ($item->getKey() === 0) {
			throw new \RuntimeException("List item is not initialized");
		}
		$segment = new Segment($item->getKey(), "w", 0, 0);
		if (!$segment->open()) {
			throw new \RuntimeException("Cannot open segment");
		}

		if ($length > $item->getSegmentSize()) {
			// Release current segment and allocate new, which can store up to $length bytes
			$segment->delete();

			$newSegmentSize = $this->calculateSegmentSize($length);
			$segment = new Segment($item->getKey(), "n", 0664, $newSegmentSize);
			if (!$segment->open()) {
				throw new \RuntimeException("Cannot reallocate segment");
			}
			$item->setSegmentSize($newSegmentSize);
		}

		$segment->write(0, $data);

		$item->setDataSize($length);
		$item->touchTimestamp();
		$this->write();
	}

	/**
	 * Returns total size of the list in bytes
	 * @param $capacity
	 * @return int
	 */
	public function getListSizeInBytes($capacity): int {
		return self::HEADER_SIZE + SegmentListItem::SIZE * $capacity;
	}

	/**
	 * Get segment size which can store at least $length bytes of data
	 * Function *MUST* return number greater than zero even for $length = 0
	 * @param $length
	 * @returns int
	 */
	private function calculateSegmentSize($length): int {
		$size = 1;
		while (true) {
			if ($length > $size) {
				$size = ceil($size * 1.2);
			}
			else {
				break;
			}
		}
		return $size;
	}

	/**
	 * @param $id
	 * @return int
	 */
	private function getSegmentKey($id): int {
		return $this->itemsSegmentStartId + $id;
	}

	public function _debugMemoryDump() {
		$segment = new Segment($this->segmentId, "a", 0, 0);
		if (!$segment->open()) {
			printf("Error: cannot open shared memory segment %08X\n", $this->segmentId);
			return;
		}

		$size = $segment->size();

		printf("Segment memory size: %d\n", $size);

		$unpacked = unpack("Ncapacity", $segment->read(0, 4));
		$capacity = $unpacked['capacity'];

		$wantSize = $this->getListSizeInBytes($capacity);

		printf("Segment size must be: %s(%s)\n", $wantSize, ($wantSize == $size) ? 'ok' : 'bad');
		printf("List capacity: %d\n", $capacity);
		printf("Elements:\n");

		$now = time();

		$prevEmpty = false;
		$hasAsterisk = false;
		for ($i = 0; $i < $capacity; $i++) {
			$line = '';

			$itemOffset = self::HEADER_SIZE + $i * SegmentListItem::SIZE;
			$item = SegmentListItemFactory::decode($segment->read($itemOffset, SegmentListItem::SIZE));

			$diff = abs($now - $item->getTimestamp());
			$past = $now >= $item->getTimestamp();

			if ($item->getKey() === 0) {
				$line .= sprintf("[%4d] %10s", $i, 'empty');
				$emptyItem = true;
				if ($item->getSegmentSize() !== 0) {
					$emptyItem = false;
					$line .= sprintf(" (!) seg size = %d ", $item->getSegmentSize());
				}
				if ($item->getDataSize() !== 0) {
					$emptyItem = false;
					$line .= sprintf(" (!) data size = %d ", $item->getDataSize());
				}
				if ($item->getTimestamp() !== 0) {
					$emptyItem = false;
					$line .= sprintf(" (!) timestamp = %d ", $item->getTimestamp());
				}
			}
			else {
				$emptyItem = false;
				$line .= sprintf("[%4d] 0x%08X %6d %6d %d", $i, $item->getKey(), $item->getSegmentSize(),
					$item->getDataSize(), $item->getTimestamp());
				$line .= sprintf("(%d seconds %s)", $diff, $past ? 'ago' : 'in future');
			}

			if ($emptyItem && $prevEmpty) {
				if (!$hasAsterisk) {
					print " *\n";
					$hasAsterisk = true;
				}
				continue;
			}
			else {
				$hasAsterisk = false;
				print $line . "\n";
			}

			$prevEmpty = $emptyItem;
		}
	}
}
