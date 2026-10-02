<?php

namespace App\Domain\Ingest\Exceptions;

use RuntimeException;

/**
 * The teacher cancelled the upload while it was still being read. Not a
 * failure: the job stops, tidies up after itself, and says nothing.
 */
class IngestCancelled extends RuntimeException {}
