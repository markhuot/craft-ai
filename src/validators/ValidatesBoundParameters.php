<?php

namespace markhuot\craftai\validators;

/**
 * Marker interface for validators that should run against the bound argument
 * values (after binders have resolved scalars into models). Implement both
 * this and ValidatesUnboundParameters to run in both phases.
 */
interface ValidatesBoundParameters {}
