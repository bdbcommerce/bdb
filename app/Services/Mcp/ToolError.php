<?php

namespace App\Services\Mcp;

use RuntimeException;

/** A tool-level error shown to the AI client (isError: true), not a protocol error. */
class ToolError extends RuntimeException {}
