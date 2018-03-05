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
	private $cache;
	private $translator;
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

	public function __construct(Cache $cache, Translator $translator, Journaling $journaling, string $sourceUnit, array $tags, string $basePath, string $namespace, $di)
	{
		$this->cache = $cache;
		$this->translator = $translator;
		$this->journaling = $journaling;
		$this->sourceUnit = $sourceUnit;
		$this->tags = $tags;
		$this->basePath = $basePath;
		$this->namespace = $namespace;
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
		if ($class === null) {
			return new Link($this->translator, $this->basePath, $this->namespace, null);
		} else {
			$resource = $this->cache->getResource($this->sourceUnit, $class, $this->tags);
			return $resource ? new Link($this->translator, $this->basePath, $this->namespace, $resource) : null;
		}
	}

	public function createRootResource(string $verb, array $conditions): self
	{
		if ($verb !== "GET" && $verb !== "POST") {
			throw new MethodNotAllowed("$verb not allowed.");
		}
		$self = new self($this->cache, $this->translator, $this->journaling, $this->sourceUnit, $this->tags, $this->basePath, $this->namespace, $this->di);
		$self->main = true;
		$resource = $self->cache->getRootResource($self->sourceUnit, array_merge($conditions, $self->tags));
		if ($resource === null) {
			throw new FileNotFound("Root resource not found.");
		}
		$self->initResource($resource, $verb, $conditions);
		$self->initResponse();
		return $self;
	}

	public function createResource(string $class, string $verb, array $conditions): self
	{
		if ($verb !== "GET" && $verb !== "POST") {
			throw new MethodNotAllowed("$verb not allowed.");
		}
		$self = new self($this->cache, $this->translator, $this->journaling, $this->sourceUnit, $this->tags, $this->basePath, $this->namespace, $this->di);
		$self->main = true;
		$resource = $self->cache->getResource($self->sourceUnit, $class, array_merge($conditions, $self->tags));
		if ($resource === null) {
			throw new FileNotFound("Resource $class not found.");
		}
		$self->initResource($resource, $verb, $conditions);
		$self->initResponse();
		return $self;
	}

	public function createAttachedResource(string $class): self
	{
		$self = new self($this->cache, $this->translator, $this->journaling, $this->sourceUnit, $this->tags, $this->basePath, $this->namespace, $this->di);
		$self->main = false;
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

	public function call(array $values, ?array $preserve): Response
	{
		$badRequest = new BadRequest();
		$badRequest->setResource($this->class);
		$this->preRecon($values);
		foreach ($this->fields as $field) {
			$this->object->{$field[0]} = $this->checkField($field, $values[$field[0]]??null, $badRequest, $field[0]);
		}
		if ($badRequest->hasMessages()) {
			throw $badRequest;
		}

		$this->object->{$this->method}($this->journaling, $this->response, $this->di);

		if ($this->verbflags->hasFields() && $this->response instanceof OK) {
			$this->postRecon();

			$translatorParameters = get_object_vars($this->object);
			foreach ($translatorParameters as $key => $value) {
				if (!is_scalar($value)) {
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

		return $this->response;
	}

	private function checkField(array $fieldDescriptor, $value, BadRequest $badRequest, string $path = "")
	{
		[$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon] = $fieldDescriptor;

		// flags check
		$flags = new FieldFlags($flags);
		if ($flags->isRequired() && !isset($value) && ($flags->isMeta() || $flags->isState() || $this->verb === "POST")) {
			$badRequest->addMessage("$path is required");
		}
		if ($flags->isReadonly() && isset($value)) {
			$badRequest->addMessage("$path is readonly");
		}
		if ($flags->isDisabled() && isset($value)) {
			$badRequest->addMessage("$path is disabled");
		}

		// type check
		if (isset($value)) {
			$type = Type::createType($type);
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
								$object[i]->{$field[0]} = $this->checkField($field, $value[$i][$field[0]], $badRequest, "$path\[$i\].{$field[0]}");
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
											$matrix[$x][$y]->{$field[0]} = $this->checkField($field, $row[$y][$field[0]], $badRequest, "$path\[$x\]\[$y\].{$field[0]}");
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
						$object->{$field[0]} = $this->checkField($field, $value[$field[0]], $badRequest, "$path.{$field[0]}");
					}
					return $object;
				}

			// tuple
			} elseif ($type instanceof TupleType) {
				$tuple = [];
				foreach ($type->getFieldDescriptors() as $i => $field) {
					$tuple[$i] = $this->checkField($field, $value[$i], $badRequest, "$path\[$i\]");
				}
				return $tuple;

			// array
			} elseif ($flags->isArray()) {
				if (is_array($value)) {
					foreach ($value as $v) {
						if (!$type->filter($v)) {
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
								if (!$type->filter($value)) {
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
					if (!$type->filter($v)) {
						$badRequest->addMessage("$path does not have a valid value: {$value}");
					}
				}

			// normal
			} else {
				if (!$type->filter($value)) {
					$badRequest->addMessage("$path does not have a valid value: ".print_r($value, true));
				}
			}
		}
		return $value ?? $defaultValue;
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
		foreach ($fieldDescriptors as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon]) {
			$flags = new FieldFlags($flags);
			if ($flags->isState()) {
				if (isset($source->$name)) {
					$state[$name] = $preserve[$name] ?? $source->$name ?? null;
				}
			} elseif ($flags->isMeta()) {
				if (!isset($dest->meta)) {
					$content->meta = new stdClass;
				}
				[$content->fields[], $content->meta->$name] = $this->createField($preserve[$name] ?? $source->$name ?? null,
					$translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon);
			} elseif ($flags->isData()) {
				[$content->fields[], $content->data] = $this->createField($preserve[$name] ?? $source->$name ?? null,
					$translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon);
			} else {
				if (!isset($content->data)) {
					$content->data = new stdClass;
				}
				[$content->fields[], $content->data->$name] = $this->createField($preserve[$name] ?? $source->$name ?? null,
					$translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon);
			}
		}
		return [$content, $state];
	}

	private function createField($value, $translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon)
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
		if ($autocomplete) {
			$field->autocomplete = $autocomplete;
		}
		$flags->meta($field);
		$type = Type::createType($type);
		$type->meta($field);
		if ($type instanceof ObjectType) {
			$field->fields = [];
			if ($flags->isArray() || $flags->isMatrix()) {
				$value = $value ?? [];
				foreach ($type->getFieldDescriptors() as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon]) {
					$flags = new FieldFlags($flags);
					[$field->fields[], $i] = $this->createField(null,
						$translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon);
				}
			} else {
				$value = $value ?? new stdClass;
				foreach ($type->getFieldDescriptors() as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon]) {
					$flags = new FieldFlags($flags);
					[$field->fields[], $value->$name] = $this->createField($value->$name ?? null,
						$translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon);
				}
			}
		} elseif ($type instanceof TupleType) {
			$field->fields = [];
			$value = $value ?? [];
			$i = 0;
			foreach ($type->getFieldDescriptors() as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon]) {
				$flags = new FieldFlags($flags);
				[$field->fields[], $value[$i]] = $this->createField($value[$i] ?? null,
					$translatorParameters, $name, $type, $defaultValue, $flags, $autocomplete, $label, $icon);
				++$i;
			}
		}
		return [$field, $value];
	}

	private function recurseField($name, $type, $flags, $value, $defaultValue, $preserve, $label, $icon, &$state, array $translatorParameters): array
	{
		$flags = new FieldFlags($flags);
		if ($flags->isState()) {
			if (isset($source)) {
				$state = $source;
			}
		} else {
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
			if ($autocomplete) {
				$field->autocomplete = $autocomplete;
			}
			$flags->meta($field);
			$type = Type::createType($type);
			$type->meta($field);
			if ($type instanceof ObjectType) {
				$subdest = new stdClass;
				$default = ($flags->isArray() || $flags->isMatrix()) ? [] : new stdClass;
				$substate = $this->recurseFields($source->$name ?? $default, $subdest, $type->getFieldDescriptors(), $translatorParameters, $preserve[$name] ?? null);
				if (isset($subdest->fields)) {
					$field->fields = $subdest->fields;
				}
				if (isset($subdest->meta)) {
					if (!isset($dest->meta)) $dest->meta = new stdClass;
					$dest->meta->$name = $subdest->meta;
				}
				if (isset($subdest->data)) {
					if ($flags->isData()) {
						$dest->data = $subdest->data;
					} else {
						if (!isset($dest->data)) $dest->data = new stdClass;
						$dest->data->$name = $subdest->data;
					}
				}
				if ($substate) {
					$state = $substate;
				}
			} elseif ($type instanceof TupleType) {
			} elseif (!is_array($source)) {
				if ($flags->isMeta()) {
					if (!isset($dest->meta)) $dest->meta = new stdClass;
					$dest->meta->$name = $preserve[$name] ?? $source->$name ?? null;
				} elseif ($flags->isData() && !isset($dest->data)) {
					$dest->data = $preserve[$name] ?? $source->$name ?? null;
				} else {
					if (!isset($dest->data)) $dest->data = new stdClass;
					$dest->data->$name = $preserve[$name] ?? $source->$name ?? null;
				}
			}
			$dest->fields[] = $field;
		}
	}

	/**
	 * Pre recondition the resource in case recondition fields have changed.
	 */
	private function preRecon(array $values): void
	{
		$cascade = 0;
		$maxcascade = 5;
		$cascadeConditions = $this->preReconRecurse($this->fields, $this->conditions, $values);
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
			$cascadeConditions = $this->preReconRecurse($this->fields, $conditions, $values);
		} while ($conditions != $cascadeConditions && $cascade++ < $maxcascade);
	}

	private function preReconRecurse(array $fieldDescriptors, array $conditions, $values, string $prefix = ""): array
	{
		foreach ($fieldDescriptors as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon]) {
			$flags = new FieldFlags($flags);
			if ($flags->isRecon()) {
				if (!array_key_exists($prefix.$name, $conditions) && array_key_exists($name, $values)) {
					$conditions[$prefix.$name] = $values[$name];
				}
			}
			$type = Type::createType($type);
			if ($type instanceof ObjectType && isset($values[$name])) {
				if (is_array($values[$name])) {
					$conditions = $this->preReconRecurse($type->getFieldDescriptors(), $conditions, $values[$name], $name."_");
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
		foreach ($fieldDescriptors as [$name, $type, $defaultValue, $flags, $autocomplete, $label, $icon]) {
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
