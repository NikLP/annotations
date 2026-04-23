<?php

declare(strict_types=1);

namespace Drupal\annotations;

/**
 * Glyph constants for Annotations status icons.
 *
 * Centralises the Unicode characters used with the annotations_status_icon
 * theme hook so all modules render consistent symbols.
 */
class AnnotationsGlyph {

  /* Checkmark — yes, included, complete. */
  const CHECK = '&#x2714;';

  /* Cross — no, excluded, empty. */
  const CROSS = '&#x2718;';

  /* Half-filled circle — partial coverage. */
  const PARTIAL = '&#x25D1;';

  /* Pencil — edit action. */
  const PENCIL = '📝';

}
