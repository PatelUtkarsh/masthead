<?php
/**
 * Who gets notified when a post enters a stage.
 *
 * @package Masthead
 */

declare(strict_types=1);

namespace Masthead\Model;

enum NotifyMode: string {
	case None     = 'none';
	case Assignee = 'assignee';
	case Editors  = 'editors';
	case Everyone = 'everyone';
}
