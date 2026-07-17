<?php

namespace App\Exceptions;

/**
 * Output LLM terpotong di batas max_tokens. Deterministik — retry dengan
 * konfigurasi sama pasti gagal lagi, jadi job harus fail langsung tanpa retry.
 */
class LlmTruncated extends \RuntimeException {}
