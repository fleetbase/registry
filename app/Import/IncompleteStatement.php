<?php

namespace App\Import;

/**
 * Thrown by the dump parser when a statement continues on the next line.
 */
class IncompleteStatement extends \RuntimeException {}
