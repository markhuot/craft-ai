<?php

namespace markhuot\craftai\validators;

/**
 * Marker interface for validators that should run against the raw, unbound
 * argument values (before binders transform them into richer types).
 *
 * Validators implementing neither this nor ValidatesBoundParameters default
 * to the unbound phase.
 */
interface ValidatesUnboundParameters {}
