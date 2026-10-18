<?php
/**
 * What happens when a stage reaches its WIP limit.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Model;

enum LimitMode: string {
	case Warn  = 'warn';
	case Block = 'block';
}
