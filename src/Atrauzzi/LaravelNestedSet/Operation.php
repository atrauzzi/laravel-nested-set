<?php namespace Atrauzzi\LaravelNestedSet;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;


class Operation {

	/**
	 * Node on which the move operation will be performed
	 *
	 * @var Model|NestedSet
	 */
	protected $node = NULL;

	/**
	 * Destination node
	 *
	 * @var Model|NestedSet
	 */
	protected $target = NULL;

	/**
	 * Move target position, one of: child, left, right
	 *
	 * @var string
	 */
	protected $position = NULL;

	/**
	 * Memorized 1st boundary.
	 *
	 * @var int
	 */
	protected $bound1 = NULL;

	/**
	 * Memorized 2nd boundary.
	 *
	 * @var int
	 */
	protected $bound2 = NULL;

	/**
	 * Memorized boundaries array.
	 *
	 * @var array
	 */
	protected $boundaries = NULL;

	/**
	 * Memorized new parent id for the node being moved.
	 *
	 * @var int
	 */
	protected $parentId = NULL;

	/**
	 * The event dispatcher instance.
	 *
	 * @var \Illuminate\Events\Dispatcher
	 */
	//protected static $dispatcher;

	/**
	 * Create a new Move class instance.
	 *
	 * @param Model|NestedSet $node
	 * @param Model|NestedSet $target
	 * @param string $position
	 */
	public function __construct(Model $node, Model $target, $position) {

		$this->node = $node;
		$this->target = $this->resolveNode($target);
		$this->position = $position;

		//$this->setEventDispatcher($node->getEventDispatcher());

	}
	/**
	 * Easy static accessor for performing a move operation.
	 *
	 * @param Model|NestedSet $node
	 * @param Model|int $target
	 * @param string $position
	 */
	public static function move($node, $target, $position) {
		/** @var Operation $instance */
		$instance = new static($node, $target, $position);
		$instance->perform();
	}

	/**
	 * Perform the move operation.
	 */
	public function perform() {

		$this->guardAgainstImpossibleMove();

		//if ($this->fireMoveEvent('moving') === false)
		//	return $this->node;

		if($this->hasChange()) {
			$self = $this;

			$this->node->getConnection()->transaction(function() use ($self) {
				$self->updateStructure();
			});

			$this->target->reload();

			$this->node->setDepth();

			foreach($this->node->getDescendants() as $descendant)
				$descendant->save();

			$this->node->reload();
		}

		$this->fireMoveEvent('moved', false);

	}

	/**
	 * Runs the SQL query associated with the update of the indexes affected
	 * by the move operation.
	 *
	 * @return int
	 */
	public function updateStructure() {

		list($a, $b, $c, $d) = $this->boundaries();

		$connection = $this->node->getConnection();
		$grammar    = $connection->getQueryGrammar();

		$currentId      = $this->node->getKey();
		$parentId       = $this->parentId();
		$leftColumn     = $this->node->getQualifiedColumn('nest_left');
		$rightColumn    = $this->node->getQualifiedColumn('nest_right');
		$parentColumn   = $this->node->getQualifiedColumn('parent_id');
		$wrappedLeft    = $grammar->wrap($leftColumn);
		$wrappedRight   = $grammar->wrap($rightColumn);
		$wrappedParent  = $grammar->wrap($parentColumn);
		$wrappedId      = $grammar->wrap($this->node->getKeyName());

		$lftSql = "
			CASE
			WHEN $wrappedLeft BETWEEN $a AND $b THEN $wrappedLeft + $d - $b
			WHEN $wrappedLeft BETWEEN $c AND $d THEN $wrappedLeft + $a - $c
			ELSE $wrappedLeft END
      	";

		$rgtSql = "
			CASE
			WHEN $wrappedRight BETWEEN $a AND $b THEN $wrappedRight + $d - $b
			WHEN $wrappedRight BETWEEN $c AND $d THEN $wrappedRight + $a - $c
			ELSE $wrappedRight END
      	";

		$parentSql = "
			CASE
			WHEN $wrappedId = $currentId THEN $parentId
			ELSE $wrappedParent END
		";

		return $this->node
			->newNestedSetQuery()
			->where(function (Builder $query) use ($leftColumn, $rightColumn, $a, $d) {
				$query
					->whereBetween($leftColumn, array($a, $d))
					->orWhereBetween($rightColumn, array($a, $d))
				;
			})
			->update([
				$leftColumn   => $connection->raw($lftSql),
				$rightColumn  => $connection->raw($rgtSql),
				$parentColumn => $connection->raw($parentSql)
			])
		;
	}

	/**
	 * Resolves suplied node. Basically returns the node unchanged if
	 * supplied parameter is an instance of Model. Otherwise it will try
	 * to find the node in the database.
	 *
	 * @param   Model|int
	 * @return  Model
	 */
	protected function resolveNode($node) {
		if ( $node instanceof Model ) return $node->reload();

		return $this->node->newNestedSetQuery()->find($node);
	}

	/**
	 * Check wether the current move is possible and if not, rais an exception.
	 *
	 * @return void
	 */
	protected function guardAgainstImpossibleMove() {
		if ( !$this->node->exists )
			throw new MoveNotPossibleException('A new node cannot be moved.');

		if ( array_search($this->position, array('child', 'left', 'right')) === FALSE )
			throw new MoveNotPossibleException("Position should be one of ['child', 'left', 'right'] but is {$this->position}.");

		if ( $this->node->equals($this->target) )
			throw new MoveNotPossibleException('A node cannot be moved to itself.');

		if ( $this->target->insideSubtree($this->node) )
			throw new MoveNotPossibleException('A node cannot be moved to a descendant of itself (inside moved tree).');

		if ( !is_null($this->target) && !$this->node->inSameScope($this->target) )
			throw new MoveNotPossibleException('A node cannot be moved to a different scope.');
	}

	/**
	 * Computes the boundary.
	 *
	 * @return int
	 */
	protected function bound1() {
		if ( !is_null($this->bound1) ) return $this->bound1;

		switch ( $this->position ) {
			case 'child':
				$this->bound1 = $this->target->getRight();
				break;

			case 'left':
				$this->bound1 = $this->target->getLeft();
				break;

			case 'right':
				$this->bound1 = $this->target->getRight() + 1;
				break;
		}

		$this->bound1 = (($this->bound1 > $this->node->getRight()) ? $this->bound1 - 1 : $this->bound1);
		return $this->bound1;
	}

	/**
	 * Computes the other boundary.
	 * TODO: Maybe find a better name for this... ¿?
	 *
	 * @return int
	 */
	protected function bound2() {
		if ( !is_null($this->bound2) ) return $this->bound2;

		$this->bound2 = (($this->bound1() > $this->node->getRight()) ? $this->node->getRight() + 1 : $this->node->getLeft() - 1);
		return $this->bound2;
	}

	/**
	 * Computes the boundaries array.
	 *
	 * @return array
	 */
	protected function boundaries() {
		if ( !is_null($this->boundaries) ) return $this->boundaries;

		// we have defined the boundaries of two non-overlapping intervals,
		// so sorting puts both the intervals and their boundaries in order
		$this->boundaries = array(
			$this->node->getLeft()  ,
			$this->node->getRight() ,
			$this->bound1()         ,
			$this->bound2()
		);
		sort($this->boundaries);

		return $this->boundaries;
	}

	/**
	 * Computes the new parent id for the node being moved.
	 *
	 * @return int
	 */
	protected function parentId() {
		if ( !is_null($this->parentId) ) return $this->parentId;

		$this->parentId = $this->target->getParentId();
		if ( $this->position == 'child' )
			$this->parentId = $this->target->getKey();

		// We are probably dealing with a root node here
		if ( is_null($this->parentId) ) $this->parentId = 'NULL';

		return $this->parentId;
	}

	/**
	 * Check wether there should be changes in the downward tree structure.
	 *
	 * @return boolean
	 */
	protected function hasChange() {
		return !(
			$this->bound1() == $this->node->nest_left
			|| $this->bound1() == $this->node->nest_left
		);
	}

	/**
	 * Get the event dispatcher instance.
	 *
	 * @return \Illuminate\Events\Dispatcher
	 */
	public static function getEventDispatcher() {
		return static::$dispatcher;
	}

	/**
	 * Set the event dispatcher instance.
	 *
	 * @param  \Illuminate\Events\Dispatcher
	 * @return void
	 */
	public static function setEventDispatcher(Dispatcher $dispatcher) {
		static::$dispatcher = $dispatcher;
	}

	/**
	 * Fire the given move event for the model.
	 *
	 * @param  string $event
	 * @param  bool   $halt
	 * @return mixed
	 */
	protected function fireMoveEvent($event, $halt = true) {
		if ( !isset(static::$dispatcher) ) return true;

		// Basically the same as \Illuminate\Database\Eloquent\Model->fireModelEvent
		// but we relay the event into the node instance.
		$event = "eloquent.{$event}: ".get_class($this->node);

		$method = $halt ? 'until' : 'fire';

		return static::$dispatcher->$method($event, $this->node);
	}

}
