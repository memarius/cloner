<?php namespace Bkwld\Cloner;

// Deps

use App\Models\ModelClone;
use App\Models\ModelCloneProgress;
use App\Models\PersonType;
use App\Models\Team;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Core class that traverses a model's relationships and replicates model
 * attributes
 */
class Cloner {

	/**
	 * @var AttachmentAdapter
	 */
	private $attachment;

	/**
	 * @var Events
	 */
	private $events;

	/**
	 * @var string
	 */
	private $write_connection;

	private ModelClone|null $modelClone = null;

	/**
	 * DI
	 *
	 * @param AttachmentAdapter $attachment
	 */
	public function __construct(
		?AttachmentAdapter $attachment = null,
		?Events $events = null,
		?ModelClone $modelClone = null
	) {
		$this->attachment = $attachment;
		$this->events = $events;
		$this->modelClone = $modelClone;
	}

	/**
	 * Clone a model instance and all of it's files and relations
	 *
	 * @param  \Illuminate\Database\Eloquent\Model $model
	 * @param  \Illuminate\Database\Eloquent\Relations\Relation $relation
	 * @param  array $attr Extra attributes for each clone
	 * @return \Illuminate\Database\Eloquent\Model The new model instance
	 */
	public function duplicate(
		object $model, 
		null|string|Relation $relation = null, 
		mixed $attr = null, 
		?ModelClone $modelClone = null,
		bool $recursive = true
	) {
		if($modelClone && $modelClone->id) $this->modelClone = $modelClone;

		//Model is source model that has already been cloned; return existing clone
		$existingModel = $this->fetchExistingClone($model);
		
		//Model should not be cloned but existing relations be kept
		if(empty($existingModel) && $this->isCloneExempt($model)) 
		{
			$existingModel = $model;
		}
	
		if(filled($existingModel))
		{
			if ($relation && !is_a($relation, 'Illuminate\Database\Eloquent\Relations\BelongsTo')) {
				$relation->save($existingModel);
			}
			return $existingModel;
		}

		if(filled($this->modelClone) && $this->isClone($model)){
			//Model is cloned model that has already been cloned, return itself
			if ($relation && !is_a($relation, 'Illuminate\Database\Eloquent\Relations\BelongsTo')) {
				$relation->save($model);
			}
			return $model;
		}

		//uncloned model, do whole cloning process
		$clone = $this->cloneModel($model);

		//TODO if $model/$clone are Pivots && filled($attr), then ->fill($attr)

		try {
			$this->dispatchOnCloningEvent($clone, $relation, $model, null, $attr);
		}catch(\Webmozart\Assert\InvalidArgumentException $e)
		{
			Log::error("Caught Webmozart Invalid Argument Exception", [
				"clone" => $clone,
				"model" => $model,
				"relation" => $relation,
				"error" => $e
			]);
		}

		DB::transaction(function () use($clone, $model, $relation) {
			if ($relation && !is_a($relation, 'Illuminate\Database\Eloquent\Relations\BelongsTo')) {
				$relation->save($clone);
			} else {
				$clone->save();
			}

			$this->saveCloneProcess($model, $clone);
		}, 5);

		
		//ToDo: Queue?
		$this->cloneMedia($model, $clone);
		if($recursive) $this->cloneRelations($model, $clone);

		$this->dispatchOnClonedEvent($clone, $model);

		return $clone;
	}

	private function isCloneExempt($model)
	{
		if(!$this->modelClone) return false;

		return $this->modelClone->cloneExempts(get_class($model))->where([
			["exemptable_id", $model->getKey()],
			["exemptable_type", get_class($model)]
		])->exists();
	}

	/*
	*	Check if model has already been cloned in this ModelClone Process
	*	Only works when ModelClone is set
	*/
	private function isClone($model)
	{
		if(empty($this->modelClone)) return false;

		$cloneProgress = $this->morphClonedBy($model)->first();
		if(!$cloneProgress) return false;

		return $cloneProgress->modelClone()->is($this->modelClone);
	}

	/**
	 * Clone a model instance to a specific database connection
	 *
	 * @param  \Illuminate\Database\Eloquent\Model $model
	 * @param  string $connection A Laravel database connection
	 * @param  array $attr Extra attributes for each clone
	 * @return \Illuminate\Database\Eloquent\Model The new model instance
	 */
	public function duplicateTo($model, $connection, $attr = null) {
		$this->write_connection = $connection; // Store the write database connection
		$clone = $this->duplicate($model, null, $attr); // Do a normal duplicate
		$this->write_connection = null; // Null out the connection for next run
		return $clone;
	}

	/**
	 * Create duplicate of the model
	 *
	 * @param  \Illuminate\Database\Eloquent\Model $model
	 * @return \Illuminate\Database\Eloquent\Model The new model instance
	 */
	protected function cloneModel($model) {
		$exempt = method_exists($model, 'getCloneExemptAttributes') ?
			$model->getCloneExemptAttributes() : null;
		$clone = $model->replicate($exempt);
		if ($this->write_connection) $clone->setConnection($this->write_connection);

		return $clone;
	}

	protected function fetchExistingClone($sourceModel): object|null
	{
		$cacheKey = filled($this->modelClone) ? "cloner-{$this->modelClone->id}-{$sourceModel->getTable()}-{$sourceModel->getKey()}" : "cloner-{$sourceModel->getTable()}-{$sourceModel->getKey()}";
		
		if(Cache::has($cacheKey))
		{
			$existingClone = $sourceModel->newQuery()->find(Cache::get($cacheKey));
			if(!$existingClone) return null;
			return $existingClone;
		}
		
		if(filled($this->modelClone))
		{
			$cloneProgress = $this->modelClone->modelCloneProgresses()->where([
				['source_id', "=", $sourceModel->getKey()],
				['model_type', "=", get_class($sourceModel)]
			])->first();
			if(!$cloneProgress) return null;
			return $cloneProgress->clone;
		}
		
		return null;
	}

	protected function saveCloneProcess($model, $clone)
	{
		/*
		*		TODO: $model/$clone can be of a class that is a pivot without incrementing - how will the clone progress be saved?
		*
		*/
		if(filled($this->modelClone)) {
			$this->modelClone->modelCloneProgresses()->create([
				"model_type" => get_class($model),
				"source_id" => $model->getKey(),
				"clone_id" => $clone->getKey()
			]);

			$cacheKeyCloned = "cloner-{$this->modelClone->id}-{$model->getTable()}-{$model->getKey()}";
			$cacheKeyClonedBy = "cloner-{$this->modelClone->id}-{$model->getTable()}-{$clone->getKey()}";

		} else {
			$cacheKeyCloned = "cloner-{$model->getTable()}-{$model->getKey()}";
			$cacheKeyClonedBy = "cloner-{$model->getTable()}-{$clone->getKey()}";
		}

		Cache::put($cacheKeyCloned, $clone->getKey(), now()->addHours(24));
		Cache::put($cacheKeyClonedBy, $model->getKey(), now()->addHours(24));
	}

	/**
	 * Duplicate all attachments, given them a new name, and update the attribute
	 * value
	 *
     * @param  \Illuminate\Database\Eloquent\Model $model
	 * @param  \Illuminate\Database\Eloquent\Model $clone
	 * @return void
	 */
	protected function cloneMedia($source, $clone) {
		if(App::environment('local')) return;

        if(!method_exists($source, 'getMedia')) return;

        foreach($source->getMedia('*') as $mediaItem)
        {
            try {
                $mediaItem->copy($clone, $mediaItem->collection_name, $mediaItem->disk);
            }catch(\Exception $e)
            {
                Log::error("Could not copy MediaItem for class {$class}", [
                    "mediaId" => $mediaItem->id,
                    "sourceModelId" => $source->id,
                    "clonedModelId" => $clone->id,
                    "exception" => $e
                ]);
            }
            
        }
	}

	/**
	 * @param  \Illuminate\Database\Eloquent\Model $clone
	 * @param  \Illuminate\Database\Eloquent\Relations\Relation $relation
	 * @param  \Illuminate\Database\Eloquent\Model $src The orginal model
	 * @param  array $attr Extra attributes for each clone
	 * @param  boolean $child
	 * @return void
	 */
	protected function dispatchOnCloningEvent($clone, $relation = null, $src = null, $child = null, $attr = null)
	{
		// Set the child flag
		if ($relation) $child = true;
        if($attr) $attr = json_decode(json_encode($attr), FALSE);
		// Notify listeners via callback or event
		if (method_exists($clone, 'onCloning')) $clone->internalOnCloning($src, $child, $this->modelClone ?? null, $attr);
		$this->events->dispatch('cloner::cloning: '.get_class($src), [$clone, $src, $attr]);
	}

		/**
	 * @param  \Illuminate\Database\Eloquent\Model $clone
	 * @param  \Illuminate\Database\Eloquent\Model $src The orginal model
	 * @return void
	 */
	protected function dispatchOnClonedEvent($clone, $src)
	{
		// Notify listeners via callback or event
		if (method_exists($clone, 'onCloned')) $clone->onCloned($src, $this->modelClone ?? null);
		$this->events->dispatch('cloner::cloned: '.get_class($src), [$clone, $src]);
	}

	/**
	 * Loop through relations and clone or re-attach them
	 *
	 * @param  \Illuminate\Database\Eloquent\Model $model
	 * @param  \Illuminate\Database\Eloquent\Model $clone
	 * @return void
	 */
	protected function cloneRelations($model, $clone) {
		if (!method_exists($model, 'getCloneableRelations')) return;
		foreach($model->getCloneableRelations() as $relation_name) {
			try{
				$this->duplicateRelation($model, $relation_name, $clone);
			}catch(\Illuminate\Database\QueryException $e)
			{
				Log::error("Query Exception trying to duplicate Relation {$relation_name}", [
					"model" => $model,
					"clone" => $clone,
					"exception" => $e
				]);
			}
		}
	}

	/**
	 * Duplicate relationships to the clone
	 *
	 * @param  \Illuminate\Database\Eloquent\Model $model
	 * @param  string $relation_name
	 * @param  \Illuminate\Database\Eloquent\Model $clone
	 * @return void
	 */
	protected function duplicateRelation($model, $relation_name, $clone) {
		$relation = call_user_func([$model, $relation_name]);
		if (is_a($relation, 'Illuminate\Database\Eloquent\Relations\BelongsToMany')) {
			//$this->duplicatePivotedRelation($relation, $relation_name, $clone);
			$this->duplicatePivotedAndRelated($relation, $relation_name, $clone);
		} else $this->duplicateDirectRelation($relation, $relation_name, $clone);
	}

	protected function duplicatePivotedAndRelated($relation, $relation_name, $clone) {

		// If duplicating between databases, do not duplicate relations. The related
		// instance may not exist in the other database or could have a different
		// primary key.
		if ($this->write_connection) return;

		// Loop trough current relations and attach to clone
		$relation->as('pivot')->get()->each(function ($related) use ($clone, $relation_name, $relation) 
		{
			//$this->checkBoundary($related, $clone, $relation, $relation_name);

			//duplicate if available, otherwise just copy
			$duplicatedRelated = $this->duplicate($related);
			$duplicatedRelated->save();

			if($clone->$relation_name()->getPivotClass() == Pivot::class)
			{
				//Standard Pivot
				$pivot_attributes = Arr::except($related->pivot->getAttributes(), [
					$related->pivot->getRelatedKey(),
					$related->pivot->getForeignKey(),
					$related->pivot->getCreatedAtColumn(),
					$related->pivot->getUpdatedAtColumn()
				]);
	
				/*foreach (array_keys($pivot_attributes) as $attributeKey) {
					$pivot_attributes[$attributeKey] = $foreign->pivot->getAttribute($attributeKey);
				}*/
	
				if ($related->pivot->incrementing) {
					unset($pivot_attributes[$related->pivot->getKeyName()]);
				}
	
				$pivot_attributes = Arr::whereNotNull($pivot_attributes);
	
				//Check if this relationship has been cloned already by checking if it exists with the key of the duplicated related and the exact pivot attributes
				if($clone->$relation_name()->withPivotValue($pivot_attributes)->where($related->pivot->getTable() . "." . $related->pivot->getRelatedKey(), $duplicatedRelated->id)->exists()) return;
	
				$clone->$relation_name()->attach($duplicatedRelated, $pivot_attributes);
			}else {
				//Custom Pivot Class that could potentially have custom clone attributes/relationships itself
				$fullPivotModel = $related->pivot->fresh();	//pivot might not have all attributes loaded from database before this. Could be moved to duplicate function(?)
				$duplicatedPivot = $this->duplicate($fullPivotModel, false, [
					$related->pivot->getForeignKey() => $clone->id,
					$related->pivot->getRelatedKey() => $duplicatedRelated->id
				]);
			}

		});
	}

	/**
	 * Duplicate a one-to-many style relation where the foreign model is ALSO
	 * cloned and then associated
	 *
	 * @param  \Illuminate\Database\Eloquent\Relations\Relation $relation
	 * @param  string $relation_name
	 * @param  \Illuminate\Database\Eloquent\Model $clone
	 * @return void
	 */
	protected function duplicateDirectRelation($relation, $relation_name, $clone) {
		$relation->get()->each(function($foreign) use ($clone, $relation_name, $relation) {
			//$this->checkBoundary($foreign, $clone, $relation, $relation_name);

			$clonedForeign = $this->duplicate($foreign, $clone->$relation_name());

			if (is_a($clone->$relation_name(), 'Illuminate\Database\Eloquent\Relations\BelongsTo')) {
				$clone->$relation_name()->associate($clonedForeign);
				$clone->save();
			}
		});
	}

	/*
	private function checkBoundary($foreign, $clone, $relation, $relation_name)
	{
		if($this->currentTeam && 
			get_class($foreign) === get_class($this->currentTeam) && 
			!$foreign->is($this->currentTeam) && 
			!$foreign->is($this->currentTeam->modelClones()->where('model_clone_id', $this->modelClone->id)->first()->clone)
		)
		//if(get_class($foreign) == PersonType::class && $this->currentTeam && $foreign->id == 2255) 
		{
			Log::error("duplicatePivotedAndRelated: Cloning Team that is not supposed to be cloned.", [
				"relation" => $relation,
				"relationName" => $relation_name,
				"clone" => $clone,
				"related" => $foreign
			]);
			throw new \Error("Cloning Team that is not supposed to be cloned");
		}

		if(filled($foreign->team) && !$foreign->team->is($this->currentTeam) && !$foreign->team->is($this->currentTeam->modelClones()->where('model_clone_id', $this->modelClone->id)->first()->clone))
		{
			Log::error("Trying to duplicate a foreign that does not have the same team.", [
				"relation" => $relation,
				"relationName" => $relation_name,
				"clone" => $clone,
				"related" => $foreign
			]);
			throw new \Error("Cloning Foreign with different team");
		}
	}
		*/


	private function morphModelClones($model)
	{
		return $model->morphMany(related: ModelCloneProgress::class, name: 'source', type: 'model_type', id: 'source_id');
	}

	private function morphClonedBy($model)
	{
		return $model->morphOne(related: ModelCloneProgress::class, name: 'clone', type: 'model_type', id: 'clone_id');	
	}
}
