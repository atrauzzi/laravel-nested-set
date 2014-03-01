<?php namespace Atrauzzi\LaravelNestedSet {

	use Atrauzzi\LaravelNestedSet\Operation;
	use Illuminate\Database\Eloquent\Collection;
	use Illuminate\Database\Eloquent\Model;
	use Illuminate\Database\Eloquent\Relations\BelongsTo;
	use Illuminate\Database\Eloquent\Relations\HasMany;
	use Illuminate\Database\Eloquent\Builder;


	/**
	 * Trait NestedSet
	 * @package Atrauzzi\LaravelNestedSet
	 *
	 * Apply this trait to any subclass of Illuminate\Database\Eloquent\Model to give it nested-set capabilities.
	 *
	 * The following attributes are assumed to exist on the model:
	 *
	 * 		nest_left
	 * 		nest_right
	 * 		nest_level
	 * 		nest_parent_id
	 *
	 */
	trait NestedSetImpl {

		/**
		 * @var array Columns defined here will be used to separate multiple trees from each other.
		 */
		protected $forestBy = [];

		//
		// Standard Laravel Relations
		//

		/**
		 * @return BelongsTo
		 */
		public function parent() {
			return $this->belongsTo(get_called_class(), 'nest_parent_id');
		}

		/**
		 * @return HasMany
		 */
		public function children() {
			return $this->hasMany(get_called_class(), 'nest_parent_id');
		}

		//
		// Basic Querying
		//

		/**
		 * Returns all ancestors to the current instance.
		 *
		 * @return Collection|null
		 */
		public function ancestors() {
			return $this->ancestorsQuery()->get();
		}
		/**
		 * @return Builder
		 */
		public function ancestorsQuery() {
			return $this
				->newNestedSetQuery()
				->where($this->getQualifiedColumn('nest_left'), '<', $this->nest_left)
				->where($this->getQualifiedColumn('nest_right'), '>', $this->nest_right)
			;
		}

		/**
		 * Returns all descendants of the current instance.
		 *
		 * @return Collection|null
		 */
		public function descendants() {
			return $this->descendantsQuery()->get();
		}

		/**
		 * @return Builder
		 */
		public function descendantsQuery() {
			return $this
				->newNestedSetQuery()
				->where($this->getQualifiedColumn('nest_left'), '>', $this->nest_left)
				->where($this->getQualifiedColumn('nest_left'), '<', $this->nest_right)
			;
		}

		/**
		 * Returns all siblings to the current instance.
		 *
		 * @return Collection|null
		 */
		public function siblings() {
			return $this->siblingsQuery()->get();
		}
		/**
		 * @return Builder
		 */
		public function siblingsQuery() {
			return $this
				->newNestedSetQuery()
				->where($this->getQualifiedColumn('nest_parent_id'), '=', $this->nest_parent_id)
				->where($this->getQualifiedKeyName(), '!=', $this->getKey())
			;
		}

		/**
		 * Retrieves the sibling to the left.
		 *
		 * @return Model|null
		 */
		public function leftSibling() {
			return $this
				->siblingsQuery()
				->where($this->getQualifiedColumn('nest_left'), '<', $this->nest_left)
				->orderBy(sprintf('%s DESC', $this->getQualifiedColumn('nest_left')))
				->first()
			;
		}

		/**
		 * Retrieves the sibling to the right.
		 *
		 * @return Model|null
		 */
		public function rightSibling() {
			return $this
				->siblingsQuery()
				->where($this->getQualifiedColumn('nest_left'), '>', $this->nest_left)
				->first()
			;
		}

		/**
		 * Returns the root relative to the current instance.
		 *
		 * @return $this|mixed|null
		 */
		public function root() {

			// ToDo: Test!

			// If the current node exists as part of the tree in the database.
			if($this->exists) {
				return $this
					->ancestors()
					->first()
				;
			}
			// If the current node hasn't been saved yet, but has been assigned a parent.
			elseif($this->nest_parent_id) {
				return $this->newNestedSetQuery()
					->join(
						$this->getTable('child'),
						$this->getQualifiedColumn('nest_left'),
						'<=',
						$this->getQualifiedColumn('nest_left', 'child_left')
					)
					->join(
						$this->getTable('child'),
						$this->getQualifiedColumn('nest_right'),
						'>=',
						$this->getQualifiedColumn('nest_right', 'child_right')
					)
					->where($this->getQualifiedKeyName('child_left'), '=', $this->nest_parent_id)
					->where($this->getQualifiedKeyName('child_right'), '=', $this->nest_parent_id)
					->first()
				;
			}

			// The current node has no associations (yet) and is therefore a root of it's own tree.
			return $this;

		}


		//
		// Scopes
		//

		/**
		 * Only root nodes.
		 *
		 * @param Builder $query
		 * @return Builder|static
		 */
		public function scopeRoot(Builder $query) {
			return $query->whereNull($this->getQualifiedColumn('nest_level'));
		}

		/**
		 * Only leaf nodes.
		 *
		 * @param Builder $query
		 * @return Builder|static
		 */
		public function scopeLeaves(Builder $query) {
			// ToDo: Test when a database table prefix exists.
			return $query->whereRaw(sprintf(
				'%s - %s = 1',
				$this->getQualifiedColumn('nest_right'),
				$this->getQualifiedColumn('nest_left')
			));
		}

		/**
		 * Exclude specific nodes.
		 *
		 * @param Builder $query
		 * @param $id
		 * @return Builder|static
		 */
		public function scopeWithout(Builder $query, $id) {
			return $query->where($this->getQualifiedKeyName(), '!=', $id);
		}

		//
		// Check Methods
		//

		public function isRoot() {
			return !$this->nest_level && !$this->nest_parent_id;
		}

		public function isLeaf() {
			return $this->nest_right - $this->nest_left == 1;
		}

		public function isChild() {
			return !$this->isRoot();
		}

		/**
		 * Whether the supplied instance is a descendant.
		 *
		 * @param Model $other
		 * @return bool
		 */
		public function isDescendantOf(Model $other) {
			return
				$this->nest_left > $other->nest_left
				&& $this->nest_left < $other->nest_right
				&& $this->inSameTree($other)
			;
		}

		/**
		 * Whether the supplied instance is an ancestor.
		 *
		 * @param Model $other
		 * @return bool
		 */
		public function isAncestorOf(Model $other) {
			return
				$this->nest_left < $other->nest_left
				&& $this->nest_right > $other->nest_left
				&& $this->inSameTree($other)
			;
		}

		/**
		 * Whether the current & supplied instances are from the same tree.
		 *
		 * @param Model $other
		 * @return bool
		 */
		public function inSameTree(Model $other) {

			foreach($this->forestBy as $column)
				if($this->$column != $other->$column)
					return false;

			return true;

		}

		//
		// Operations
		//

		/**
		 * Core movement method.
		 *
		 * @param Model $target
		 * @param string $position
		 */
		protected function moveTo(Model $target, $position) {
			Operation::move($this, $target, $position);
		}

		/**
		 * Find the left sibling and move to left of it.
		 */
		public function moveLeft() {
			$this->makePreviousSiblingOf($this->leftSibling());
		}

		/**
		 * Find the right sibling and move to the right of it.
		 */
		public function moveRight() {
			$this->makeNextSiblingOf($this->rightSibling());
		}

		/**
		 * Alias for moveToRightOf
		 */
		public function makeNextSiblingOf(Model $node) {
			$this->moveTo($node, 'right');
		}

		/**
		 * Alias for moveToLeftOf
		 */
		public function makePreviousSiblingOf(Model $node) {
			$this->moveTo($node, 'left');
		}

		/**
		 * Make the node a child of ...
		 */
		public function makeChildOf(Model $node) {
			$this->moveTo($node, 'child');
		}

		/**
		 * Make current node a root node.
		 */
		public function makeRoot() {
			$this->makeNextSiblingOf($this->root());
		}

		/**
		 * Sets default values for left and right fields.
		 *
		 * @return void
		 */
		public function setDefaultLeftAndRight() {

			$withHighestRight = $this
				->newQuery()
				->orderBy($this->getQualifiedColumn('nest_left'), 'desc')
				->take(1)
				->first()
			;

			$maxRgt = $withHighestRight ? $withHighestRight->nest_right : 0;

			$this->nest_left = $maxRgt + 1;
			$this->nest_right = $maxRgt + 2;

		}


		//
		// DB Utility Methods
		//

		/**
		 * Returns the fully qualified name of the column.
		 *
		 * @param string $column
		 * @param string $columnAlias
		 * @param string $tableAlias
		 * @return string
		 */
		public function getQualifiedColumn($column, $columnAlias = null, $tableAlias = null) {
			return sprintf(
				'%s.%s%s',
				$tableAlias ?: $this->getTable(),
				$column,
				$columnAlias ? sprintf(' AS %s', $columnAlias) : null
			);
		}

		/**
		 * Returns the fully qualified name of the primary key for the current model.
		 *
		 * @param null $tableAlias
		 * @return string
		 */
		public function getQualifiedKeyName($tableAlias = null) {
			return sprintf(
				'%s%s',
				parent::getQualifiedKeyName(),
				$tableAlias ? sprintf(' AS $s', $tableAlias) : null
			);
		}

		/**
		 * Returns the table name for the current model with the added benefit of supporting aliasing.
		 *
		 * @param null $alias
		 * @return string
		 */
		public function getTable($alias = null) {
			return sprintf(
				'%s%s',
				parent::getTable(),
				$alias ? sprintf(' AS %s', $alias) : null
			);
		}

		/**
		 * Begins a new query for nested set use.
		 *
		 * @param bool $excludeDeleted
		 * @return Builder
		 */
		public function newNestedSetQuery($excludeDeleted = true) {

			$query = $this
				->newQuery($excludeDeleted)
				->orderBy(sprintf('%s ASC', $this->getQualifiedColumn('nest_left')))
			;

			// If this model supports multiple trees..
			if($this->forestBy)
				foreach($this->forestBy as $column)
					$query->where($this->getQualifiedColumn($column));

			return $query;

		}

	}

}