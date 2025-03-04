<?php namespace Bkwld\Cloner;

// Deps

use App\Models\ModelClone;
use App\Models\ModelCloneProgress;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

	private ModelClone|null $modelClone;

	/**
	 * DI
	 *
	 * @param AttachmentAdapter $attachment
	 */
	public function __construct(AttachmentAdapter $attachment = null,
		Events $events = null) {
		$this->attachment = $attachment;
		$this->events = $events;
	}

	/**
	 * Clone a model instance and all of it's files and relations
	 *
	 * @param  \Illuminate\Database\Eloquent\Model $model
	 * @param  \Illuminate\Database\Eloquent\Relations\Relation $relation
	 * @param  array $attr Extra attributes for each clone
	 * @return \Illuminate\Database\Eloquent\Model The new model instance
	 */
	public function duplicate($model, $relation = null, $attr = null, $modelClone = null) {
		if($modelClone) $this->modelClone = $modelClone;

		//Check if has been cloned
		$existingClone = $this->fetchExistingClone($model);
		if(filled($existingClone))
		{
			if ($relation) {
				if (!is_a($relation, 'Illuminate\Database\Eloquent\Relations\BelongsTo')) {
					$relation->save($existingClone);
				}
			}
			return $existingClone;
		}

		if($this->modelClone && $this->isClone($model)){
			if ($relation) {
				if (!is_a($relation, 'Illuminate\Database\Eloquent\Relations\BelongsTo')) {
					$relation->save($model);
				}
			}
			return $model;
		}

		$clone = $this->cloneModel($model);
		$this->dispatchOnCloningEvent($clone, $relation, $model,null, $attr);

		if ($relation) {
			if (!is_a($relation, 'Illuminate\Database\Eloquent\Relations\BelongsTo')) {
				$relation->save($clone);
			}
		} else {
			$clone->save();
		}

		$this->duplicateAttachments($model, $clone);
		$clone->save();

		if($this->modelClone) {
			$this->modelClone->modelCloneProgresses()->create([
				"model_type" => get_class($model),
				"source_id" => $model->getKey(),
				"clone_id" => $clone->getKey()
			]);
		} else {
			$cacheKey = "cloner-{$model->getTable()}-{$model->getKey()}";
			Cache::put($cacheKey, $clone->getKey(), now()->addHours(24));	
		}

		$this->cloneRelations($model, $clone);

		$this->dispatchOnClonedEvent($clone, $model);

		return $clone;
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
		if($this->modelClone)
		{
			$cloneProgress = $this->modelClone->modelCloneProgresses()->where('source_id', $sourceModel->getKey())->first();
			if(!$cloneProgress) return null;
			return $cloneProgress->clone;
		}

		$cacheKey = "cloner-{$sourceModel->getTable()}-{$sourceModel->getKey()}";
		if(!Cache::has($cacheKey)) return null;
		
		$existingClone = $sourceModel->newQuery()->find(Cache::get($cacheKey));
		if(!$existingClone) return null;
		return $existingClone;
	}

	/**
	 * Duplicate all attachments, given them a new name, and update the attribute
	 * value
	 *
     * @param  \Illuminate\Database\Eloquent\Model $model
	 * @param  \Illuminate\Database\Eloquent\Model $clone
	 * @return void
	 */
	protected function duplicateAttachments($model, $clone) {
		if (!$this->attachment || !method_exists($clone, 'getCloneableFileAttributes')) return;
		foreach($clone->getCloneableFileAttributes() as $attribute) {
			if (!$original = $model->getAttribute($attribute)) continue;
			$clone->setAttribute($attribute, $this->attachment->duplicate($original, $clone));
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
		if (method_exists($clone, 'onCloning')) $clone->onCloning($src, $child, $attr);
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
		if (method_exists($clone, 'onCloned')) $clone->onCloned($src);
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
			$this->duplicateRelation($model, $relation_name, $clone);
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

	/**
	 * Duplicate a many-to-many style relation where we are just attaching the
	 * relation to the dupe
	 *
	 * @param  \Illuminate\Database\Eloquent\Relations\Relation $relation
	 * @param  string $relation_name
	 * @param  \Illuminate\Database\Eloquent\Model $clone
	 * @return void
	 */
	protected function duplicatePivotedRelation($relation, $relation_name, $clone) {

		// If duplicating between databases, do not duplicate relations. The related
		// instance may not exist in the other database or could have a different
		// primary key.
		if ($this->write_connection) return;

		// Loop trough current relations and attach to clone
		$relation->as('pivot')->get()->each(function ($foreign) use ($clone, $relation_name) {
			$pivot_attributes = Arr::except($foreign->pivot->getAttributes(), [
				$foreign->pivot->getRelatedKey(),
				$foreign->pivot->getForeignKey(),
				$foreign->pivot->getCreatedAtColumn(),
				$foreign->pivot->getUpdatedAtColumn()
			]);

			foreach (array_keys($pivot_attributes) as $attributeKey) {
				$pivot_attributes[$attributeKey] = $foreign->pivot->getAttribute($attributeKey);
			}

			if ($foreign->pivot->incrementing) {
				unset($pivot_attributes[$foreign->pivot->getKeyName()]);
			}

			$clone->$relation_name()->attach($foreign, $pivot_attributes);
		});
	}

	protected function duplicatePivotedAndRelated($relation, $relation_name, $clone) {

		// If duplicating between databases, do not duplicate relations. The related
		// instance may not exist in the other database or could have a different
		// primary key.
		if ($this->write_connection) return;

		// Loop trough current relations and attach to clone
		$relation->as('pivot')->get()->each(function ($foreign) use ($clone, $relation_name) {
			
			//ToDo: check if pivot has been cloned already?
			//dd($foreign);

			//duplicate if available, otherwise just copy
			//$duplicatedForeign = method_exists($foreign, 'duplicate') ? $foreign->duplicate() : $this->cloneModel($foreign);
			$duplicatedForeign = $this->duplicate($foreign);
			$duplicatedForeign->save();


			$pivot_attributes = Arr::except($foreign->pivot->getAttributes(), [
				$foreign->pivot->getRelatedKey(),
				$foreign->pivot->getForeignKey(),
				$foreign->pivot->getCreatedAtColumn(),
				$foreign->pivot->getUpdatedAtColumn()
			]);

			foreach (array_keys($pivot_attributes) as $attributeKey) {
				$pivot_attributes[$attributeKey] = $foreign->pivot->getAttribute($attributeKey);
			}

			if ($foreign->pivot->incrementing) {
				unset($pivot_attributes[$foreign->pivot->getKeyName()]);
			}

			//Check if this relationship has been cloned already by checking if it exists with the foreign key of the duplicated foreign and the exact pivot attributes
			if($clone->$relation_name()->withPivotValue($pivot_attributes)->where($foreign->pivot->getForeignKey(), $duplicatedForeign->id)->exists()) return;

			$clone->$relation_name()->attach($duplicatedForeign, $pivot_attributes);
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
		$relation->get()->each(function($foreign) use ($clone, $relation_name) {
			$cloned_relation = $this->duplicate($foreign, $clone->$relation_name());
			if (is_a($clone->$relation_name(), 'Illuminate\Database\Eloquent\Relations\BelongsTo')) {
				$clone->$relation_name()->associate($cloned_relation);
				$clone->save();
			}
		});
	}


	private function morphModelClones($model)
	{
		return $model->morphMany(related: ModelCloneProgress::class, name: 'source', type: 'model_type', id: 'source_id');
	}

	private function morphClonedBy($model)
	{
		return $model->morphOne(related: ModelCloneProgress::class, name: 'clone', type: 'model_type', id: 'clone_id');	
	}
}
