<?php declare(strict_types=1);

namespace Sturdy\Activity\Response;

final class Forbidden extends Error
{
	/**
	 * Get the response status code
	 *
	 * @return int  the status code
	 */
	public function getStatusCode(): int
	{
		return 403;
	}

	/**
	 * Get the response status text
	 *
	 * @return string  the status text
	 */
	public function getStatusText(): string
	{
		return "Forbidden";
	}
}
