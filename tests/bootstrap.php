<?php

/**
 * @file
 * Bootstrap for ai_claude_agent_sdk unit tests.
 *
 * Registers PSR-4 namespaces for module classes that would normally be
 * resolved by Drupal's module handler at runtime.
 */

$loader = require __DIR__ . '/../../../../vendor/autoload.php';

// Register namespaces for modules under test.
$base = realpath(__DIR__ . '/../../../..');

// This module.
$loader->addPsr4('Drupal\\ai_claude_agent_sdk\\', $base . '/workbench/MODULE_BUP/ai_claude_agent_sdk/src');

// Test namespace.
$loader->addPsr4('Drupal\\Tests\\ai_claude_agent_sdk\\', $base . '/workbench/MODULE_BUP/ai_claude_agent_sdk/tests/src');

// Drupal core user module (for UserInterface in executor tests).
$loader->addPsr4('Drupal\\user\\', $base . '/web/core/modules/user/src');

// Runner submodule — source and test namespaces.
$loader->addPsr4('Drupal\\ai_claude_agent_sdk_runner\\', $base . '/workbench/MODULE_BUP/ai_claude_agent_sdk/modules/ai_claude_agent_sdk_runner/src');
$loader->addPsr4('Drupal\\Tests\\ai_claude_agent_sdk_runner\\', $base . '/workbench/MODULE_BUP/ai_claude_agent_sdk/modules/ai_claude_agent_sdk_runner/tests/src');

// Contrib modules required by runner tests.
$loader->addPsr4('Drupal\\ai\\', $base . '/web/modules/contrib/ai/src');
$loader->addPsr4('Drupal\\ai_assistant_api\\', $base . '/web/modules/contrib/ai/modules/ai_assistant_api/src');
$loader->addPsr4('Drupal\\ai_agents\\', $base . '/web/modules/contrib/ai_agents/src');
