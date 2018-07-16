<?php declare(strict_types=1);

namespace Sturdy\Activity;

use stdClass;
use Sturdy\Activity\Meta\{
	CacheItem_Resource,
	Field,
	FieldFlags
};
use Sturdy\Activity\Meta\Type\{
	Type,
	ObjectType,
	TupleType
};
use Sturdy\Activity\Response\{
	Accepted,
	BadRequest,
	Created,
	FileNotFound,
	InternalServerError,
	MethodNotAllowed,
	NoContent,
	OK,
	Response,
	SeeOther,
	UnsupportedMediaType
};

final class Resource
{
	private $sharedStateStore;
	private $cache;
	private $translator;
	private $jsonDeserializer;
	private $journaling;
	private $sourceUnit;
	private $tags;
	private $basePath;
	private $di;

	private $response;

	private $main;
	private $verb;
	private $conditions;
	private $hints;
	private $fields;
	private $class;
	private $object;
	private $method;
	private $verbflags;
	private $mainClass;
	private $query;

	public function __construct(SharedStateStore $sharedStateStore, Cache $cache, Translator $translator, JsonDeserializer $jsonDeserializer, Journaling $journaling, string $sourceUnit, array $tags, string $basePath, string $namespace, string $mainClass, array $query, $di)
	{
		$this->sharedStateStore = $sharedStateStore;
		$this->cache = $cache;
		$this->translator = $translator;
		$this->jsonDeserializer = $jsonDeserializer;
		$this->journaling = $journaling;
		$this->sourceUnit = $sourceUnit;
		$this->tags = $tags;
		$this->basePath = $basePath;
		$this->namespace = $namespace;
		$this->mainClass = $mainClass;
		$this->query = $query;
		$this->di = $di;
	}

	/**
	 * Create a link to be used inside the data section.
	 *
	 * @param  string $class  the class of the resource
	 * @return ?Link          containing the href property and possibly the templated property
	 */
	public function createLink(?string $class): ?Link
	{
		$this->updateStore();
		if ($class === null) {
			return new Link($this->sharedStateStore, $this->translator, $this->basePath, $this->namespace, null);
		} else {
			$resource = $this->cache->getResource($this->sourceUnit, $class, $this->tags);
			return $resource ? new Link($this->sharedStateStore, $this->translator, $this->basePath, $this->namespace, $resource, $this->mainClass === $class, $this->query) : null;
		}
	}

	public function createRootResource(string $verb, array $conditions): self
	{
		if ($verb !== "GET" && $verb !== "POST") {
			throw new MethodNotAllowed("$verb not allowed.");
		}
		$self = new self($this->sharedStateStore, $this->cache, $this->translator, $this->jsonDeserializer, $this->journaling, $this->sourceUnit, $this->tags, $this->basePath, $this->namespace, $this->mainClass, $this->query, $this->di);
		$self->main = true;
		$resource = $self->cache->getRootResource($self->sourceUnit, array_merge($conditions, $self->tags));
		if ($resource === null) {
			throw new FileNotFound("Root resource not found.");
		}
		$this->mainClass = $self->mainClass = $resource->getClass();
		$self->initResource($resource, $verb, $conditions);
		$self->initResponse();
		return $self;
	}

	public function createResource(string $class, string $verb, array $conditions): self
	{
		if ($verb !== "GET" && $verb !== "POST") {
			throw new MethodNotAllowed("$verb not allowed.");
		}
		$self = new self($this->sharedStateStore, $this->cache, $this->translator, $this->jsonDeserializer, $this->journaling, $this->sourceUnit, $this->tags, $this->basePath, $this->namespace, $this->mainClass, $this->query, $this->di);
		$self->main = true;
		$resource = $self->cache->getResource($self->sourceUnit, $class, array_merge($conditions, $self->tags));
		if ($resource === null) {
			throw new FileNotFound("Resource $class not found.");
		}
		$self->initResource($resource, $verb, $conditions);
		$self->initResponse();
		return $self;
	}

	public function createAttachedResource(string $class, bool $main = false): self
	{
		$self = new self($this->sharedStateStore, $this->cache, $this->translator, $this->jsonDeserializer, $this->journaling, $this->sourceUnit, $this->tags, $this->basePath, $this->namespace, $this->mainClass, $this->query, $this->di);
		$self->main = $main;
		$resource = $self->cache->getResource($self->sourceUnit, $class, $self->tags);
		if ($resource === null) {
			throw new FileNotFound("Resource $class not found.");
		}
		$self->initResource($resource, "GET", []);
		if ($self->verbflags->getStatus() !== Meta\Verb::OK) {
			throw new InternalServerError("Attached resources must return an OK status code.");
		}
		$self->response = $this->response;
		return $self;
	}

	private function initResource(CacheItem_Resource $resource, string $verb, array $conditions): void
	{
		$this->class = $resource->getClass();
		$this->verb = $verb;
		$this->conditions = $conditions;
		$this->hints = $resource->getHints();
		$this->fields = $resource->getFields() ?? [];
		$this->object = new $this->class;
		[$this->method, $this->verbflags] = $resource->getVerb($verb);
		$this->verbflags = new Meta\VerbFlags($this->verbflags);
	}

	private function initResponse(): void
	{
		switch ($this->verbflags->getStatus()) {
			case Meta\Verb::OK:
				$this->response = new OK($this);
				Http::setLogger($this->response);
				break;

			case Meta\Verb::NO_CONTENT:
				$this->response = new NoContent();
				break;

			case Meta\Verb::SEE_OTHER:
				$this->response = new SeeOther($this);
				break;

			default:
				throw new InternalServerError("[{$this->class}::{$method}] Unkown status code.");
		}
	}

	public function getObject()/*: object*/
	{
		return $this->object;
	}

	public function getMethod(): string
	{
		return $this->method;
	}

	public function call(array $values, array $query, ?array $preserve): Response
	{
		// pre call
		$badRequest = new BadRequest();
		$badRequest->setResource($this->class);
		$this->preRecon($values, $query);
		foreach ($this->fields as $field) {
			$this->object->{$field[0]} = $this->checkField($field, $values[$field[0]] ?? null, $query, $badRequest, $field[0]);
		}
		if ($badRequest->hasMessages()) {
			throw $badRequest;
		}

		// call
		$this->object->{$this->method}($this->journaling, $this->response, $this->di);

		// post call
		if ($this->verbflags->hasFields()) {
			if ($this->response instanceof OK) {
				if (!$this->response->isDone()) {
					$this->postRecon();

					$translatorParameters = get_object_vars($this->object);
					foreach ($translatorParameters as $key => $value) {
						if (!is_scalar($value) && $value !== null) {
							unset($translatorParameters[$key]);
						}
					}
					if (isset($this->hints[0])) {
						$this->hints[0] = ($this->translator)($this->hints[0], $translatorParameters);
					}
					$this->response->hints(...$this->hints);

					[$content, $state] = $this->createContent($this->object, $this->fields, $translatorParameters, $preserve);
					$this->response->setContent($content);
					if ($this->main && $this->verbflags->hasSelfLink()) {
						$this->response->link("self", $this->class, ["values"=>$state]);
					}
				}
			} else {
				$this->updateStore();
			}
		}

		return $this->response;
	}

	private function checkField(array $fieldDescriptor, $value, array $query, BadRequest $badRequest, string $path = "")
	{
		[$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool] = $fieldDescriptor;

		// flags check
		$flags = new FieldFlags($flags);
		if ($flags->isPrivate()) {

			return $flags->isShared() ? $this->sharedStateStore->get($pool, $name) : null;

		} else if ($flags->isMeta() || $flags->isState()) {

			$queryValue = $query[$name] ?? null;
			if ($queryValue === "") {
				$queryValue = null;
			}

			if ($flags->isRequired() && $queryValue === null) {
				$badRequest->addMessage("$path is required");
			} else {
				$type = Type::createType($type);
				if ($queryValue !== null) {
					if ($type->filter($queryValue)) {
						$queryValue = $this->jsonDeserializer->jsonDeserialize($type::type, $queryValue);
					} else {
						$badRequest->addMessage("$path does not have a valid value: ".print_r($queryValue, true));
						$queryValue = null;
					}
				} else {
					$queryValue = $this->jsonDeserializer->jsonDeserialize($type::type, $defaultValue);
				}
			}

			if ($flags->isShared() && !$flags->isReadonly()) {
				$this->sharedStateStore->set($pool, $name, $queryValue);
			}

			return $queryValue;

		} else {

			if ($flags->isRequired() && !isset($value) && $this->verb === "POST") {
				$badRequest->addMessage("$path is required");
			}

		}

		if ($flags->isReadonly() && isset($value)) {
			$badRequest->addMessage("$path is readonly");
		}
		if ($flags->isDisabled() && isset($value)) {
			$badRequest->addMessage("$path is disabled");
		}

		// type check
		$type = Type::createType($type);

		if (isset($value)) {

			// object type
			if ($type instanceof ObjectType) {
				// array of objects
				if ($flags->isArray()) {
					if (is_array($value)) {
						$object = [];
						$l = count($value);
						for ($i = 0; $i < $l; ++$i) {
							if (!isset($value[$i])) {
								$badRequest->addMessage("Expected type of $path\[$i\] is array, " . gettype($value) . " found.");
							}
							$object[i] = new stdClass;
							foreach ($type->getFieldDescriptors() as $field) {
								$object[i]->{$field[0]} = $this->checkField($field, $value[$i][$field[0]], [], $badRequest, "$path\[$i\].{$field[0]}");
							}
						}
						return $object;
					} else {
						$badRequest->addMessage("Expected type of $path is array, " . gettype($value) . " found.");
					}

				// matrix of objects
				} elseif ($flags->isMatrix()) {
					if (is_array($value)) {
						$matrix = [];
						$xl = count($value);
						for ($x = 0; $x < $xl; ++$x) {
							$row = $value[$x] ?? null;
							if (is_array($row)) {
								$yl = count($value);
								for ($y = 0; $y < $yl; ++$y) {
									if (isset($row[$y])) {
										$matrix[$x][$y] = new stdClass;
										foreach ($type->getFieldDescriptors() as $field) {
											$matrix[$x][$y]->{$field[0]} = $this->checkField($field, $row[$y][$field[0]], [], $badRequest, "$path\[$x\]\[$y\].{$field[0]}");
										}
									}
								}
							} else {
								$badRequest->addMessage("Expected type of $path is matrix, " . gettype($value) . " found.");
							}
						}
						return $matrix;
					} else {
						$badRequest->addMessage("Expected type of $path is matrix, " . gettype($value) . " found.");
					}

				// normal object
				} else {
					$object = new stdClass;
					foreach ($type->getFieldDescriptors() as $field) {
						$object->{$field[0]} = $this->checkField($field, $value[$field[0]], [], $badRequest, "$path.{$field[0]}");
					}
					return $object;
				}

			// tuple
			} elseif ($type instanceof TupleType) {
				$tuple = [];
				foreach ($type->getFieldDescriptors() as $i => $field) {
					$tuple[$i] = $this->checkField($field, $value[$i], [], $badRequest, "$path\[$i\]");
				}
				return $tuple;

			// array
			} elseif ($flags->isArray()) {
				if (is_array($value)) {
					foreach ($value as $v) {
						if ($type->filter($v)) {
							$value = $this->jsonDeserializer->jsonDeserialize($type::type, $value);
						} else {
							$badRequest->addMessage("$path does not have a valid value: {$v}");
						}
					}
				} else {
					$badRequest->addMessage("Expected type of $path is array, " . gettype($value) . " found.");
				}

			// matrix
			} elseif ($flags->isMatrix()) {
				if (is_array($value)) {
					foreach ($value as $row) {
						if (is_array($row)) {
							foreach ($row as $value) {
								if ($type->filter($value)) {
									$value = $this->jsonDeserializer->jsonDeserialize($type::type, $value);
								} else {
									$badRequest->addMessage("$path does not have a valid value: {$value}");
								}
							}
						} else {
							$badRequest->addMessage("Expected type of $path is matrix, " . gettype($value) . " found.");
						}
					}
				} else {
					$badRequest->addMessage("Expected type of $path is matrix, " . gettype($value) . " found.");
				}

			// multiple
			} elseif ($flags->isMultiple()) {
				foreach (explode(",", $value) as $v) {
					$v = trim($v);
					if ($type->filter($v)) {
						$value = $this->jsonDeserializer->jsonDeserialize($type::type, $value);
					} else {
						$badRequest->addMessage("$path does not have a valid value: {$value}");
					}
				}

			// normal
			} else {
				if ($type->filter($value)) {
					$value = $this->jsonDeserializer->jsonDeserialize($type::type, $value);
				} else {
					$badRequest->addMessage("$path does not have a valid value: ".print_r($value, true));
				}
			}

			return $value;

		} else {

			return $this->jsonDeserializer->jsonDeserialize($type::type, $defaultValue);

		}
	}

	private function updateStore()
	{
		foreach ($this->fields as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool]) {
			$flags = new FieldFlags($flags);
			if ($flags->isShared() && !$flags->isReadOnly()) {
				$this->sharedStateStore->set($pool, $name, $this->object->$name ?? null, $flags->isPersistent());
			}
		}
	}

	/**
	 * Recurse field to configure a response.
	 *
	 * @param  array  $fieldDescriptors      the field descriptors
	 * @param  array  $translatorParameters  translation parameters
	 * @param  array  $preserve              preserve values
	 * @return $state
	 */
	private function createContent($source, array $fieldDescriptors, array $translatorParameters, ?array $preserve): array
	{
		$content = new stdClass;
		$state = [];
		foreach ($fieldDescriptors as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool]) {
			$flags = new FieldFlags($flags);
			if ($flags->isState()) {
				if (isset($source->$name)) {
					$state[$name] = $preserve[$name] ?? $source->$name ?? null;
					if ($flags->isShared() && !$flags->isReadOnly()) {
						$this->sharedStateStore->set($pool, $name, $state[$name], $flags->isPersistent());
					}
				}
			} else if ($flags->isPrivate()) {
				if ($flags->isShared() && !$flags->isReadOnly()) {
					$this->sharedStateStore->set($pool, $name, $source->$name ?? null, $flags->isPersistent());
				}
			}
		}

		foreach ($fieldDescriptors as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool]) {
			$flags = new FieldFlags($flags);
			if ($flags->isState() || $flags->isPrivate()) {
				continue;
			} else if ($flags->isMeta()) {
				if (!isset($content->meta)) {
					$content->meta = new stdClass;
				}
				[$content->fields[], $content->meta->$name] = $this->createField($preserve[$name] ?? $source->$name ?? null,
					$translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool, $state);
			} else if ($flags->isData()) {
				[$content->fields[], $content->data] = $this->createField($preserve[$name] ?? $source->$name ?? null,
					$translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool, $state);
			} else {
				if (!isset($content->data)) {
					$content->data = new stdClass;
				}
				[$content->fields[], $content->data->$name] = $this->createField($preserve[$name] ?? $source->$name ?? null,
					$translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool, $state);
			}
		}

		return [$content, $state];
	}

	private function createField($value, $translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool, $state)
	{
		$field = new stdClass;
		$field->name = $name;
		if ($label) {
			$field->label = ($this->translator)($label, $translatorParameters);
		}
		if ($icon) {
			$field->icon = $icon;
		}
		if ($defaultValue !== null) {
			$field->defaultValue = $defaultValue;
		}
		if ($autocomplete !== null && $autocomplete !== "") {
			$field->autocomplete = $autocomplete;
		}
		$flags->meta($field);
		$type = Type::createType($type);
		$type->meta($field, $state);
		if ($type instanceof ObjectType) {
			$field->fields = [];
			if ($flags->isArray() || $flags->isMatrix()) {
				$value = $value ?? [];
				foreach ($type->getFieldDescriptors() as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool]) {
					$flags = new FieldFlags($flags);
					[$field->fields[], $i] = $this->createField(null,
						$translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool, $state);
				}
			} else {
				$value = $value ?? new stdClass;
				foreach ($type->getFieldDescriptors() as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool]) {
					$flags = new FieldFlags($flags);
					[$field->fields[], $value->$name] = $this->createField($value->$name ?? null,
						$translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool, $state);
				}
			}
		} else if ($type instanceof TupleType) {
			$field->fields = [];
			$value = $value ?? [];
			$i = 0;
			foreach ($type->getFieldDescriptors() as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool]) {
				$flags = new FieldFlags($flags);
				[$field->fields[], $value[$i]] = $this->createField($value[$i] ?? null,
					$translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool, $state);
				++$i;
			}
		}
		return [$field, $value];
	}

	/**
	 * Pre recondition the resource in case recondition fields have changed.
	 */
	private function preRecon(array $values, array $query): void
	{
		$cascade = 0;
		$maxcascade = 5;
		$cascadeConditions = $this->preReconRecurse($this->fields, $this->conditions, $values, $query);
		do {
			$conditions = $cascadeConditions;
			$resource = $this->cache->getResource($this->sourceUnit, $this->class, array_merge($conditions, $this->tags));
			if ($resource !== null) {
				$this->class = $resource->getClass();
				$this->hints = $resource->getHints();
				$this->fields = $resource->getFields() ?? [];
				[$this->method, $this->verbflags] = $resource->getVerb($this->verb);
				$this->verbflags = new Meta\VerbFlags($this->verbflags);
			}
			$cascadeConditions = $this->preReconRecurse($this->fields, $conditions, $values, $query);
		} while ($conditions != $cascadeConditions && $cascade++ < $maxcascade);
	}

	private function preReconRecurse(array $fieldDescriptors, array $conditions, $values, $query, string $prefix = ""): array
	{
		foreach ($fieldDescriptors as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool]) {
			$flags = new FieldFlags($flags);
			if ($flags->isRecon()) {
				if ($flags->isMeta() || $flags->isState()) {
					if (!array_key_exists($name, $conditions) && array_key_exists($name, $query)) {
						$conditions[$name] = $query[$name];
					}
				} else if ($flags->isPrivate()) {
					continue;
				} else {
					if (!array_key_exists($prefix.$name, $conditions) && array_key_exists($name, $values)) {
						$conditions[$prefix.$name] = $values[$name];
					}
				}
			}
			$type = Type::createType($type);
			if ($type instanceof ObjectType && isset($values[$name])) {
				if (is_array($values[$name])) {
					$conditions = $this->preReconRecurse($type->getFieldDescriptors(), $conditions, $values[$name], [], $name."_");
				}
			}
		}
		return $conditions;
	}

	/**
	 * Post recondition the resource in case recondition fields have changed.
	 */
	private function postRecon(): void
	{
		$cascade = 0;
		$maxcascade = 5;
		$cascadeConditions = $this->postReconRecurse($this->fields, $this->conditions, $this->object);
		do {
			$conditions = $cascadeConditions;
			$resource = $this->cache->getResource($this->sourceUnit, $this->class, array_merge($conditions, $this->tags));
			if ($resource !== null) {
				$this->class = $resource->getClass();
				$this->hints = $resource->getHints();
				$this->fields = $resource->getFields() ?? [];
				[$this->method, $this->verbflags] = $resource->getVerb($this->verb);
				$this->verbflags = new Meta\VerbFlags($this->verbflags);
			}
			$cascadeConditions = $this->postReconRecurse($this->fields, $conditions, $this->object);
		} while ($conditions != $cascadeConditions && $cascade++ < $maxcascade);
	}

	private function postReconRecurse(array $fieldDescriptors, array $conditions, /*object*/ $object, string $prefix = ""): array
	{
		foreach ($fieldDescriptors as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon, $pool]) {
			$flags = new FieldFlags($flags);
			if ($flags->isRecon()) {
				if (!array_key_exists($prefix.$name, $conditions)) {
					$conditions[$prefix.$name] = $object->$name;
				}
			}
			$type = Type::createType($type);
			if ($type instanceof ObjectType && isset($object->$name)) {
				if (is_object($object->$name)) {
					$conditions = $this->postReconRecurse($type->getFieldDescriptors(), $conditions, $object->$name, $name."_");
				}
			}
		}
		return $conditions;
	}
}
