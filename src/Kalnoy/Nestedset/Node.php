<?php

namespace Kalnoy\Nestedset;

use Exception;
use LogicException;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class Node extends Eloquent {

    /**
     * The name of "lft" column.
     *
     * @var string 
     */
    const LFT = '_lft';

    /**
     * The name of "rgt" column.
     *
     * @var string 
     */
    const RGT = '_rgt';

    /**
     * The name of "parent id" column.
     *
     * @var string 
     */
    const PARENT_ID = 'parent_id';

    /**
     * Insert direction.
     *
     * @var string 
     */
    const BEFORE = 'before';

    /**
     * Insert direction.
     *
     * @var string 
     */
    const AFTER = 'after';

    /**
     * Whether model uses soft delete.
     * 
     * @var bool
     * 
     * @since 1.1
     */
    static protected $_softDelete;

    /**
     * Whether the node is being deleted.
     * 
     * @since 2.0
     *
     * @var bool
     */
    static protected $deleting;

    /**
     * Pending operation.
     * 
     * @var array
     */
    protected $pending = [ 'root' ];

    /**
     * Whether the node has moved since last save.
     * 
     * @var bool
     */
    protected $moved = false;

    /**
     * Keep track of the number of performed operations.
     * 
     * @var int
     */
    protected static $actionsPerformed = 0;

    /**
     * {@inheritdoc}
     */
    protected static function boot()
    {
        parent::boot();

        static::$_softDelete = static::getIsSoftDelete();

        static::signOnEvents();
    }

    /**
     * Get whether model uses soft delete.
     * 
     * @return bool
     */
    protected static function getIsSoftDelete()
    {
        $instance = new static;

        return method_exists($instance, 'withTrashed');
    }

    /**
     * Sign on model events.
     */
    protected static function signOnEvents()
    {
        static::saving(function ($model)
        {
            return $model->callPendingAction();
        });

        if ( ! static::$_softDelete)
        {
            static::deleting(function ($model)
            {
                // We will need fresh data to delete node safely
                $model->refreshNode();
            });

            static::deleted(function ($model)
            {
                $model->deleteNode();
            });
        }
    }

    /**
     * {@inheritdoc}
     * 
     * Saves a node in a transaction.
     * 
     */
    public function save(array $options = array())
    {
        return $this->getConnection()->transaction(function () use ($options)
        {
            return parent::save($options);
        });
    }

    /**
     * {@inheritdoc}
     * 
     * Delete a node in transaction if model is not soft deleting.
     */
    public function delete()
    {
        if (static::$_softDelete) return parent::delete();

        return $this->getConnection()->transaction(function ()
        {
            return parent::delete();
        });
    }

    /**
     * Set an action.
     * 
     * @param string $action
     */
    protected function setAction($action)
    {
        $this->pending = func_get_args();

        return $this;
    }

    /**
     * Clear pending action.
     */
    protected function clearAction()
    {
        $this->pending = null;
    }

    /**
     * Call pending action.
     *
     * @return null|false
     */
    protected function callPendingAction()
    {
        $this->moved = false;

        if ( ! $this->pending) return;

        $method = 'action'.ucfirst(array_shift($this->pending));
        $parameters = $this->pending;

        $this->pending = null;

        $this->moved = call_user_func_array([ $this, $method ], $parameters);
    }

    /**
     * Make a root node.
     */
    protected function actionRoot()
    {
        // Simplest case that do not affect other nodes.
        if ( ! $this->exists)
        {
            $cut = $this->getLowerBound() + 1;

            $this->setAttribute(static::LFT, $cut);
            $this->setAttribute(static::RGT, $cut + 1);

            return true;
        }

        if ($this->isRoot()) return false;

        // Reset parent object
        $this->setParent(null);

        return $this->insertAt($this->getLowerBound() + 1);
    }

    /**
     * Get the lower bound.
     * 
     * @return int
     */
    protected function getLowerBound()
    {
        return (int)$this->newServiceQuery()->max(static::RGT);
    }

    /**
     * Append a node to the parent.
     *
     * @param \Kalnoy\Nestedset\Node $parent
     */
    protected function actionAppendTo(Node $parent)
    {
        return $this->actionAppendOrPrepend($parent);
    }

    /**
     * Prepend a node to the parent.
     * 
     * @param \Kalnoy\Nestedset\Node $parent
     */
    protected function actionPrependTo(Node $parent)
    {
        return $this->actionAppendOrPrepend($parent, true);
    }

    /**
     * Append or prepend a node to the parent.
     * 
     * @param \Kalnoy\Nestedset\Node $parent
     * @param bool $prepend
     */
    protected function actionAppendOrPrepend(Node $parent, $prepend = false)
    {
        if ( ! $parent->exists)
        {
            throw new LogicException('Cannot use non-existing node as a parent.');
        }

        $this->setParent($parent);

        $parent->refreshNode();

        if ($this->insertAt($prepend ? $parent->getLft() + 1 : $parent->getRgt()))
        {
            $parent->refreshNode();

            return true;
        }

        return false;
    }

    /**
     * Apply parent model.
     * 
     * @param \Kalnoy\Nestedset\Node|null $value
     */
    protected function setParent($value)
    {
        $this->attributes[static::PARENT_ID] = $value ? $value->getKey() : null;
        $this->setRelation('parent', $value);
    }

    /**
     * Insert node before or after another node.
     * 
     * @param \Kalnoy\Nestedset\Node $node
     * @param bool $after
     */
    protected function actionBeforeOrAfter(Node $node, $after = false)
    {
        if ( ! $node->exists)
        {
            throw new LogicException('Cannot insert before/after non-existing node.');
        }

        if ($this->getParentId() <> $node->getParentId())
        {
            $this->setParent($node->getAttribute('parent'));
        }

        $node->refreshNode();

        return $this->insertAt($after ? $node->getRgt() + 1 : $node->getLft());
    }

    /**
     * Insert node before other node.
     * 
     * @param \Kalnoy\Nestedset\Node $node
     */
    protected function actionBefore(Node $node)
    {
        return $this->actionBeforeOrAfter($node);
    }

    /**
     * Insert node after other node.
     * 
     * @param \Kalnoy\Nestedset\Node $node
     */
    protected function actionAfter(Node $node)
    {
        return $this->actionBeforeOrAfter($node, true);
    }

    /**
     * Refresh node's crucial attributes.
     */
    public function refreshNode()
    {
        if ( ! $this->exists || static::$actionsPerformed === 0) return;

        $attributes = $this->newServiceQuery()->getNodeData($this->getKey());

        $this->attributes = array_merge($this->attributes, $attributes);
        $this->original = array_merge($this->original, $attributes);
    }

    /**
     * Get the root node.
     *
     * @param   array   $columns
     *
     * @return  Node
     */
    static public function root(array $columns = array('*'))
    {
        return static::whereIsRoot()->first($columns);
    }

    /**
     * Relation to the parent.
     *
     * @return BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(get_class($this), static::PARENT_ID);
    }

    /**
     * Relation to children.
     *
     * @return HasMany
     */
    public function children()
    {
        return $this->hasMany(get_class($this), static::PARENT_ID);
    }

    /**
     * Get query for descendants of the node.
     *
     * @return  \Kalnoy\Nestedset\QueryBuilder
     */
    public function descendants()
    {
        return $this->newQuery()->whereDescendantOf($this->getKey());
    }

    /**
     * Get query for siblings of the node.
     * 
     * @param self::AFTER|self::BEFORE|null $dir
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function siblings($dir = null)
    {
        switch ($dir)
        {
            case self::AFTER: 
                $query = $this->next();

                break;

            case self::BEFORE:
                $query = $this->prev();

                break;

            default:
                $query = $this->newQuery()
                    ->where($this->getKeyName(), '<>', $this->getKey());

                break;
        }

        $query->where(static::PARENT_ID, '=', $this->getParentId());
        
        return $query;
    }

    /**
     * Get query for siblings after the node.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function nextSiblings()
    {
        return $this->siblings(self::AFTER);
    }

    /**
     * Get query for siblings before the node.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function prevSiblings()
    {
        return $this->siblings(self::BEFORE);
    }

    /**
     * Get query for nodes after current node.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function next()
    {
        return $this->newQuery()->whereIsAfter($this->getKey())->defaultOrder();
    }

    /**
     * Get query for nodes before current node in reversed order.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function prev()
    {
        return $this->newQuery()->whereIsBefore($this->getKey())->reversed();
    }

    /**
     * Get query for ancestors to the node not including the node itself.
     *
     * @return  \Kalnoy\Nestedset\QueryBuilder
     */
    public function ancestors()
    {
        return $this->newQuery()->whereAncestorOf($this->getKey())->defaultOrder();
    }

    /**
     * Make this node a root node.
     * 
     * @return $this
     */
    public function makeRoot()
    {
        return $this->setAction('root');
    }

    /**
     * Save node as root.
     * 
     * @return bool
     */
    public function saveAsRoot()
    {
        return $this->makeRoot()->save();
    }

    /**
     * Append and save a node.
     *
     * @param \Kalnoy\Nestedset\Node $node
     *
     * @return bool
     */
    public function append(Node $node)
    {
        return $node->appendTo($this)->save();
    }

    /**
     * Prepend and save a node.
     *
     * @param \Kalnoy\Nestedset\Node $node
     *
     * @return bool
     */ 
    public function prepend(Node $node)
    {
        return $node->prependTo($this)->save();
    }

    /**
     * Append a node to the new parent.
     *
     * @param \Kalnoy\Nestedset\Node $parent
     *
     * @return $this
     */
    public function appendTo(Node $parent)
    {
        return $this->setAction('appendTo', $parent);
    }

    /**
     * Prepend a node to the new parent.
     *
     * @param \Kalnoy\Nestedset\Node $parent
     *
     * @return $this
     */
    public function prependTo(Node $parent)
    {        
        return $this->setAction('prependTo', $parent);
    }

    /**
     * Insert self after a node.
     *
     * @param \Kalnoy\Nestedset\Node $node
     *
     * @return $this
     */
    public function after(Node $node)
    {
        return $this->setAction('after', $node);
    }

    /**
     * Insert self after a node and save.
     * 
     * @param \Kalnoy\Nestedset\Node $node
     * 
     * @return bool
     */
    public function insertAfter(Node $node)
    {
        return $this->after($node)->save();
    }

    /**
     * Insert self before node.
     *
     * @param \Kalnoy\Nestedset\Node $node
     *
     * @return $this
     */
    public function before(Node $node)
    {
        return $this->setAction('before', $node);
    }

    /**
     * Insert self before a node and save.
     * 
     * @param \Kalnoy\Nestedset\Node $node
     * 
     * @return bool
     */
    public function insertBefore(Node $node)
    {
        if ($this->before($node)->save())
        {
            // We'll' update the target node since it will be moved
            $node->refreshNode();

            return true;
        }

        return false;
    }

    /**
     * Move node up given amount of positions.
     * 
     * @param int $amount
     * 
     * @return bool
     */
    public function up($amount = 1)
    {
        if ($sibling = $this->prevSiblings()->skip($amount - 1)->first())
        {
            return $this->insertBefore($sibling);
        }

        return false;
    }

    /**
     * Move node down given amount of positions.
     * 
     * @param int $amount
     * 
     * @return bool
     */
    public function down($amount = 1)
    {
        if ($sibling = $this->nextSiblings()->skip($amount - 1)->first())
        {
            return $this->insertAfter($sibling);
        }

        return false;
    }

    /**
     * Insert node at specific position.
     *
     * @param  int $position
     *
     * @return bool
     */
    protected function insertAt($position)
    {
        ++static::$actionsPerformed;

        $result = $this->exists ? $this->moveNode($position) : $this->insertNode($position);

        return $result;
    }

    /**
     * Move a node to the new position.
     * 
     * @since 2.0
     *
     * @param int $position
     *
     * @return int
     */
    protected function moveNode($position)
    {
        $updated = $this->newServiceQuery()->moveNode($this->getKey(), $position) > 0;

        if ($updated) $this->refreshNode();

        return $updated;
    }

    /**
     * Insert new node at specified position.
     * 
     * @since 2.0
     * 
     * @param int $position
     */
    protected function insertNode($position)
    {
        $this->makeGap($position, 2);

        $height = $this->getNodeHeight();

        $this->setAttribute(static::LFT, $position);
        $this->setAttribute(static::RGT, $position + $height - 1);

        return true;
    }

    /**
     * Update the tree when the node is removed physically.
     */
    protected function deleteNode()
    {
        if (static::$deleting) return;

        $lft = $this->getLft();
        $rgt = $this->getRgt();
        $height = $rgt - $lft + 1;

        // Make sure that inner nodes are just deleted and don't touch the tree
        static::$deleting = true;

        $this->newQuery()->whereNodeBetween([ $lft, $rgt ])->delete();
        
        static::$deleting = false;

        $this->makeGap($rgt + 1, -$height);

        // In case if user wants to re-create the node
        $this->makeRoot();
    }

    /**
     * {@inheritdoc}
     * 
     * @since 2.0
     */
    public function newEloquentBuilder($query)
    {
        return new QueryBuilder($query);
    }

    /**
     * Get a new base query that includes deleted nodes.
     * 
     * @since 1.1
     */
    protected function newServiceQuery()
    {
        return static::$_softDelete ? $this->withTrashed() : $this->newQuery();
    }

    /**
     * {@inheritdoc}
     */
    public function newCollection(array $models = array())
    {
        return new Collection($models);
    }

    /**
     * {@inheritdoc}
     */
    public function newFromBuilder($attributes = array())
    {
        $instance = parent::newFromBuilder($attributes);

        $instance->clearAction();

        return $instance;
    }

    /**
     * {@inheritdoc}
     * 
     * Use `children` key on `$attributes` to create child nodes.
     * 
     * @param \Kalnoy\Nestedset\Node $parent
     * 
     */
    public static function create(array $attributes, Node $parent = null)
    {
        $children = array_pull($attributes, 'children');

        $instance = new static($attributes);

        if ($parent) $instance->appendTo($parent);

        $instance->save();

        // Now create children
        $relation = new EloquentCollection;

        foreach ((array)$children as $child)
        {
            $relation->add($child = static::create($child, $instance));

            $child->setRelation('parent', $instance);
        }

        return $instance->setRelation('children', $relation);
    }

    /**
     * Get node height (rgt - lft + 1).
     *
     * @return int
     */
    public function getNodeHeight()
    {
        if ( ! $this->exists) return 2;

        return $this->getRgt() - $this->getLft() + 1;
    }

    /**
     * Get number of descendant nodes.
     *
     * @return int
     */
    public function getDescendantCount()
    {
        return round($this->getNodeHeight() / 2) - 1;
    }

    /**
     * Set the value of model's parent id key.
     *
     * Behind the scenes node is appended to found parent node.
     *
     * @param int $value
     * 
     * @throws Exception If parent node doesn't exists
     */
    public function setParentIdAttribute($value)
    {
        if ($this->getAttribute(static::PARENT_ID) != $value) 
        {
            if ($value)
            {
                $this->appendTo(static::findOrFail($value));
            }
            else
            {
                $this->makeRoot();
            }
        }
    }

    /**
     * Get whether node is root.
     *
     * @return boolean
     */
    public function isRoot()
    {
        return $this->getAttribute(static::PARENT_ID) === null;
    }

    /**
     * Get the lft key name.    
     *
     * @return  string
     */
    public function getLftName()
    {
        return static::LFT;
    }

    /**
     * Get the rgt key name.
     *
     * @return  string
     */
    public function getRgtName()
    {
        return static::RGT;
    }

    /**
     * Get the parent id key name.
     *
     * @return  string
     */
    public function getParentIdName()
    {
        return static::PARENT_ID;
    }

    /**
     * Get the value of the model's lft key.
     *
     * @return  integer
     */
    public function getLft()
    {
        return isset($this->attributes[static::LFT]) ? $this->attributes[static::LFT] : null;
    }

    /**
     * Get the value of the model's rgt key.
     *
     * @return  integer
     */
    public function getRgt()
    {
        return isset($this->attributes[static::RGT]) ? $this->attributes[static::RGT] : null;
    }

    /**
     * Get the value of the model's parent id key.
     *
     * @return  integer
     */
    public function getParentId()
    {
        return $this->getAttribute(static::PARENT_ID);
    }

    /**
     * Shorthand for next()
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Node
     */
    public function getNext(array $columns = array('*'))
    {
        return $this->next()->first($columns);
    }

    /**
     * Shorthand for prev()
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Node
     */
    public function getPrev(array $columns = array('*'))
    {
        return $this->prev()->first($columns);
    }

    /**
     * Shorthand for ancestors()
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function getAncestors(array $columns = array('*'))
    {
        return $this->newQuery()->ancestorsOf($this->getKey(), $columns);
    }

    /**
     * Shorthand for descendants()
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function getDescendants(array $columns = array('*'))
    {
        return $this->newQuery()->descendantsOf($this->getKey(), $columns);
    }

    /**
     * Shorthand for siblings()
     *
     * @param   array   $column
     *
     * @return  \Kalnoy\Nestedset\Collection
     */
    public function getSiblings(array $columns = array('*')) 
    {
        return $this->siblings()->get($columns);
    }

    /**
     * Shorthand for nextSiblings().
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function getNextSiblings(array $columns = array('*'))
    {
        return $this->nextSiblings()->get($columns);
    }

    /**
     * Shorthand for prevSiblings().
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function getPrevSiblings(array $columns = array('*'))
    {
        return $this->prevSiblings()->get($columns);
    }

    /**
     * Get next sibling.
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Node
     */
    public function getNextSibling(array $columns = array('*'))
    {
        return $this->nextSiblings()->first($columns);
    }

    /**
     * Get previous sibling.
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Node
     */
    public function getPrevSibling(array $columns = array('*'))
    {
        return $this->prevSiblings()->reversed()->first($columns);
    }

    /**
     * Get whether a node is a descendant of other node.
     * 
     * @param \Kalnoy\Nestedset\Node $other
     * 
     * @return bool
     */
    public function isDescendantOf(Node $other)
    {
        return $this->getLft() > $other->getLft() and $this->getLft() < $other->getRgt();
    }

    /**
     * Get statistics of errors of the tree.
     * 
     * @since 2.0
     * 
     * @return array
     */
    public static function countErrors()
    {
        $model = new static;

        return $model->newServiceQuery()->countErrors();
    }

    /**
     * Get the number of total errors of the tree.
     * 
     * @since 2.0
     * 
     * @return int
     */
    public static function getTotalErrors()
    {
        return array_sum(static::countErrors());
    }

    /**
     * Get whether the tree is broken.
     * 
     * @since 2.0
     * 
     * @return bool
     */
    public static function isBroken()
    {
        return static::getTotalErrors() > 0;
    }

    /**
     * Get whether the node has moved since last save.
     * 
     * @return bool
     */
    public function hasMoved()
    {
        return $this->moved;
    }
}