<?php
namespace Drupal\my_module\Plugin\Block;

/**
 * @Block(
 *   id = "my_block"
 * )
 */
class MyBlock extends BlockBase {}

#[ConfigAction(id: "my_config_action")]
class MyConfigAction {}
