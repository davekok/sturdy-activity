<?php declare(strict_types=1);

namespace Sturdy\Activity;

use DateTime;

/**
 * Interface to the journal to be implemented by the application.
 */
interface JournalEntry
{
	/**
	 * Get the date/time on which the entry has been created.
	 *
	 * @return DateTime  the date/time
	 */
	public function getDateTime(): DateTime;

	/**
	 * Redact this entry.
	 *
	 * Redacted entries are no longer valid but where once valid.
	 * For instance when a recovery has been done after an error,
	 * the entry containing the error will have been redacted,
	 * followed by a new entry giving the correct results.
	 *
	 * @return $this
	 */
	public function redact(): JournalEntry;

	/**
	 * Get whether the entry has been redacted.
	 *
	 * @return bool
	 */
	public function isRedacted(): bool;

	/**
	 * Get object
	 *
	 * @return object  the object
	 */
	public function getObject()/*: object*/;

	/**
	 * Get action
	 *
	 * @return string  the action
	 */
	public function getAction(): string;

	/**
	 * Get the status code
	 *
	 * @return int  the status code
	 */
	public function getStatusCode(): int;

	/**
	 * Get the status text
	 *
	 * @return ?string  the status text
	 */
	public function getStatusText(): ?string;
}
