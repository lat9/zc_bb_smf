<?php
/**
 * auto loader for the SMF observer class. Load this at Breakpoint 54; the base
 * bb class will be instantiated at Breakpoint 55 and 'throw' the first (instantiate)
 * notifier at that point.
 *
 * @copyright Copyright 2013, Vinos de Frutas Tropicales (lat9): Zen Cart/Simple Machines Forum Integration
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */
  $autoLoadConfig[54][] = array('autoType'=>'class',
                                'loadFile'=>'observers/class.smf_observer.php');
  $autoLoadConfig[54][] = array('autoType'=>'classInstantiate',
                                'className'=>'smf_observer',
                                'objectName'=>'smf_observer');
// eof