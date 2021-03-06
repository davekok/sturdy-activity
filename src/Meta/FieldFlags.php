<?php declare(strict_types=1);

namespace Sturdy\Activity\Meta;

use stdClass;

/**
 * Field flags
 */
final class FieldFlags
{
	const required   =      1; // whether the field is required
	const readonly   =      2; // whether the field is readonly
	const disabled   =      4; // whether the field is disabled
	const multiple   =      8; // if multiple emails or files are allowed
	const _array     =     16; // field contains an array of the given type, can not be a meta field
	const meta       =     32; // whether this is meta data, meta data is part of the URI, making URI a templated URI
	                           // also meta data is never in the data section
	const data       =     64; // only one field may have this flag, when used on a field the value of the field will
	                           // be put in the data section and all other fields must have the meta flag
	const state      =    128; // whether the field is a state field, state fields are hidden from the client
	                           // state fields can be used to embed data in the URL without the client knowing about it
	const recon      =    256; // whether the field is a recon field, whenever the value of a recon field changes the
	                           // client should send a RECON request to the server in which case the resource is
	                           // reconditioned, depending on changing state
	const lookup     =    512; // whether the field is a lookup field, whenever the value of a lookup field changes the
	                           // client should send a LOOKUP request to the server in which case the resource is
	                           // looked up
	const matrix     =   1024; // field is a matrix or two dimensional array
	const autosubmit =   2048; // auto submit field
	const shared     =   4096; // whether the field is shared with other resources
	const _private   =  16384; // indicates that the field is private to the resource and should not be shared through
	                           // either state section of the link or in the body of the response
	const hidden     =  32768; // indicates that the field is hidden and should not be shown
	const input      =  65536; // Whether the field is rendered as an input field or just a value
	const noInput    = 131072; // Whether the field is rendered as an input field or just a value

	private $flags;           // bitmask of the above constants

	/**
	 * Constructor
	 *
	 * @param int $flags
	 */
	public function __construct(int $flags = 0)
	{
		$this->flags = $flags;
	}

	public function setRequired(): self
	{
		$this->flags |= self::required;
		return $this;
	}

	public function clearRequired(): self
	{
		$this->flags &= ~self::required;
		return $this;
	}

	public function isRequired(): bool
	{
		return (bool)($this->flags & self::required);
	}

	public function setReadonly(): self
	{
		$this->flags |= self::readonly;
		return $this;
	}

	public function clearReadonly(): self
	{
		$this->flags &= ~self::readonly;
		return $this;
	}

	public function isReadonly(): bool
	{
		return (bool)($this->flags & self::readonly);
	}

	public function setDisabled(): self
	{
		$this->flags |= self::disabled;
		return $this;
	}

	public function clearDisabled(): self
	{
		$this->flags &= ~self::disabled;
		return $this;
	}

	public function isDisabled(): bool
	{
		return (bool)($this->flags & self::disabled);
	}

	public function setMultiple(): self
	{
		$this->flags |= self::multiple;
		return $this;
	}

	public function clearMultiple(): self
	{
		$this->flags &= ~self::multiple;
		return $this;
	}

	public function isMultiple(): bool
	{
		return (bool)($this->flags & self::multiple);
	}

	public function setArray(): self
	{
		$this->flags |= self::_array;
		return $this;
	}

	public function clearArray(): self
	{
		$this->flags &= ~self::_array;
		return $this;
	}

	public function isArray(): bool
	{
		return (bool)($this->flags & self::_array);
	}

	public function setMeta(): self
	{
		$this->flags |= self::meta;
		return $this;
	}

	public function clearMeta(): self
	{
		$this->flags &= ~self::meta;
		return $this;
	}

	public function isMeta(): bool
	{
		return (bool)($this->flags & self::meta);
	}

	public function setData(): self
	{
		$this->flags |= self::data;
		return $this;
	}

	public function clearData(): self
	{
		$this->flags &= ~self::data;
		return $this;
	}

	public function isData(): bool
	{
		return (bool)($this->flags & self::data);
	}

	public function setState(): self
	{
		$this->flags |= self::state;
		return $this;
	}

	public function clearState(): self
	{
		$this->flags &= ~self::state;
		return $this;
	}

	public function isState(): bool
	{
		return (bool)($this->flags & self::state);
	}

	public function setRecon(): self
	{
		$this->flags |= self::recon;
		return $this;
	}

	public function clearRecon(): self
	{
		$this->flags &= ~self::recon;
		return $this;
	}

	public function isRecon(): bool
	{
		return (bool)($this->flags & self::recon);
	}

	public function setLookup(): self
	{
		$this->flags |= self::lookup;
		return $this;
	}

	public function clearLookup(): self
	{
		$this->flags &= ~self::lookup;
		return $this;
	}

	public function isLookup(): bool
	{
		return (bool)($this->flags & self::lookup);
	}

	public function setMatrix(): self
	{
		$this->flags |= self::matrix;
		return $this;
	}

	public function clearMatrix(): self
	{
		$this->flags &= ~self::matrix;
		return $this;
	}

	public function isMatrix(): bool
	{
		return (bool)($this->flags & self::matrix);
	}

	public function setAutoSubmit(): self
	{
		$this->flags |= self::autosubmit;
		return $this;
	}

	public function clearAutoSubmit(): self
	{
		$this->flags &= ~self::autosubmit;
		return $this;
	}

	public function isAutoSubmit(): bool
	{
		return (bool)($this->flags & self::autosubmit);
	}

	public function setShared(): self
	{
		$this->flags |= self::shared;
		return $this;
	}

	public function clearShared(): self
	{
		$this->flags &= ~self::shared;
		return $this;
	}

	public function isShared(): bool
	{
		return (bool)($this->flags & self::shared);
	}

	public function setPrivate(): self
	{
		$this->flags |= self::_private;
		return $this;
	}

	public function clearPrivate(): self
	{
		$this->flags &= ~self::_private;
		return $this;
	}

	public function isPrivate(): bool
	{
		return (bool)($this->flags & self::_private);
	}

	public function setHidden(): self
	{
		$this->flags |= self::hidden;
		return $this;
	}

	public function clearHidden(): self
	{
		$this->flags &= ~self::hidden;
		return $this;
	}

	public function isHidden(): bool
	{
		return (bool)($this->flags & self::hidden);
	}

	public function setInput(): self
	{
		$this->flags |= self::input;
		return $this;
	}

	public function clearInput(): self
	{
		$this->flags &= ~self::input;
		return $this;
	}

	public function isInput(): bool
	{
		return (bool)($this->flags & self::input);
	}

	public function setNoInput(): self
	{
		$this->flags |= self::noInput;
		return $this;
	}

	public function clearNoInput(): self
	{
		$this->flags &= ~self::noInput;
		return $this;
	}

	public function isNoInput(): bool
	{
		return (bool)($this->flags & self::noInput);
	}

	public function toInt(): int
	{
		return $this->flags;
	}

	public function meta(stdClass $meta, object $properties)
	{
		if ($this->isState() || $this->isPrivate()) {
			throw new \LogicException("State or private fields should not be visible to the client.");
		}
		if ($this->isRequired() || ($properties->required??false)) $meta->required = true;
		if ($this->isReadonly() || ($properties->readonly??false)) $meta->readonly = true;
		if ($this->isDisabled() || ($properties->disabled??false)) $meta->disabled = true;
		if ($this->isMultiple()) $meta->multiple = true;
		if ($this->isArray()) $meta->{"array"} = true;
		if ($this->isMeta()) $meta->meta = true;
		if ($this->isData()) $meta->data = true;
		if ($this->isRecon()) $meta->recon = true;
		if ($this->isLookup()) $meta->lookup = true;
		if ($this->isMatrix()) $meta->matrix = true;
		if ($this->isAutoSubmit()) $meta->autosubmit = true;
		if ($this->isHidden()) $meta->hidden = true;
		if ($this->isInput()) $meta->input = true;
		if ($this->isNoInput()) $meta->noInput = true;
	}

	public function __toString(): string
	{
		$r = "";
		if ($this->isPrivate   ()) $r .= "private ";
		if ($this->isHidden    ()) $r .= "hidden ";
		if ($this->isState     ()) $r .= "state ";
		if ($this->isMeta      ()) $r .= "meta ";
		if ($this->isData      ()) $r .= "data ";
		if ($this->isRequired  ()) $r .= "required ";
		if ($this->isReadonly  ()) $r .= "readonly ";
		if ($this->isShared    ()) $r .= "shared ";
		if ($this->isDisabled  ()) $r .= "disabled ";
		if ($this->isMultiple  ()) $r .= "multiple ";
		if ($this->isArray     ()) $r .= "array ";
		if ($this->isRecon     ()) $r .= "recon ";
		if ($this->isLookup    ()) $r .= "lookup ";
		if ($this->isMatrix    ()) $r .= "matrix ";
		if ($this->isAutoSubmit()) $r .= "autosubmit ";
		if ($this->isInput     ()) $r .= "input ";
		if ($this->isNoInput   ()) $r .= "no-input ";
		return rtrim($r);
	}
}
