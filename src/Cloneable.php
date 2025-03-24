<?php namespace Bkwld\Cloner;

// Deps
use App;
use Bkwld\Cloner\Models\ModelClone;
use Bkwld\Cloner\Models\ModelCloneProgress;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Mixin accessor methods, callbacks, and the duplicate() helper into models.
 */
trait Cloneable {


	public function modelClones()
	{
		return $this->morphMany(related: ModelCloneProgress::class, name: 'source', type: 'model_type', id: 'source_id');
	}

	public function clonedBy()
	{
		return $this->morphOne(related: ModelCloneProgress::class, name: 'clone', type: 'model_type', id: 'clone_id');	
	}

	public function cloneExempted(): MorphToMany
	{
		return $this->morphToMany(ModelClone::class, 'model_clone_exemptable', 'model_clone_exemptable', 'exemptable_id', 'model_clone_id');
	}


	/**
	 * Return the list of attributes on this model that should be cloned
	 *
	 * @return  array
	 */
	public function getCloneExemptAttributes() {

		// Always make the id and timestamps exempt
		$defaults = [
			$this->getKeyName(),
			$this->getCreatedAtColumn(),
			$this->getUpdatedAtColumn(),
		];

		// Include the model count columns in the exempt columns
		$count_columns = array_map(function($count_column) {
		    return $count_column . '_count';
        	}, $this->withCount);

		$defaults = array_merge($defaults, $count_columns);

		// It none specified, just return the defaults, else, merge them
		if (!isset($this->clone_exempt_attributes)) return $defaults;
		return array_merge($defaults, $this->clone_exempt_attributes);
	}

	/**
	 * Return a list of attributes that reference files that should be duplicated
	 * when the model is cloned
	 *
	 * @return  array
	 */
	public function getCloneableFileAttributes() {
		if (!isset($this->cloneable_file_attributes)) return [];
		return $this->cloneable_file_attributes;
	}

	/**
	 * Return the list of relations on this model that should be cloned
	 *
	 * @return  array
	 */
	public function getCloneableRelations() {
		$cloneableRelations = isset($this->cloneable_relations) ? $this->cloneable_relations : [];

		$traitCloneableRelations = config("app.trait_cloneable_relations");

		$usedTraits = class_uses($this);

		foreach ($usedTraits as $trait) {
			if (isset($traitCloneableRelations[$trait])) {
				$cloneableRelations = array_merge($cloneableRelations, $traitCloneableRelations[$trait]);
			}
		}

		return $cloneableRelations;
	}

	/**
	 * Add a relation to cloneable_relations uniquely
	 *
	 * @param  string $relation
	 * @return void
	 */
	public function addCloneableRelation($relation) {
		$relations = $this->getCloneableRelations();
		if (in_array($relation, $relations)) return;
		$relations[] = $relation;
		$this->setAttribute('cloneable_relations', $relations);
		//$this->cloneable_relations = $relations;
	}

	/**
	 * Clone the current model instance
	 * @param  array $attr Extra attributes for each clone
	 * @return \Illuminate\Database\Eloquent\Model The new, saved clone
	 */
	public function duplicate(mixed $attr = null, ?ModelClone $modelClone = null, bool $recursive = true) {
		return App::make('cloner')->duplicate(
			model: $this, 
			relation: null, 
			attr: $attr, 
			modelClone: $modelClone, 
			recursive: $recursive
		);
	}

	/**
	 * Clone the current model instance to a specific Laravel database connection
	 *
	 * @param  string $connection A Laravel database connection
	 * @param  array $attr Extra attributes for each clone
	 * @return \Illuminate\Database\Eloquent\Model The new, saved clone
	 */
	public function duplicateTo($connection, $attr = null) {
		return App::make('cloner')->duplicateTo($this, $connection, $attr);
	}

	/**
	 * A no-op callback that gets fired when a model is cloning but before it gets
	 * committed to the database
	 *
	 * @param  \Illuminate\Database\Eloquent\Model $src
	 * @param  boolean $child
     * @param  array $attr Extra attributes for each clone
	 * @return void
	 */
	public function onCloning($src, $child = null, ?ModelClone $modelClone = null, $attr = null) {}

		/**
	 * A no-op callback that gets fired when a model is cloning but before it gets
	 * committed to the database
	 *
	 * @param  \Illuminate\Database\Eloquent\Model $src
	 * @param  boolean $child
     * @param  array $attr Extra attributes for each clone
	 * @return void
	 */
	public function internalOnCloning($src, $child = null, ?ModelClone $modelClone = null, $attr = null) 
	{
		//Fill Pivot with new foreignKeys
		if($this instanceof \Illuminate\Database\Eloquent\Relations\Pivot && filled($attr)){
            $this->fill((array) $attr);
		}

		$this->onCloning($src, child: $child, modelClone: $modelClone, attr: $attr);
	}


	/**
	 * A no-op callback that gets fired when a model is cloned and saved to the
	 * database
	 *
	 * @param  \Illuminate\Database\Eloquent\Model $src
	 * @return void
	 */
	public function onCloned($src, ?ModelClone $modelClone = null) {}

}