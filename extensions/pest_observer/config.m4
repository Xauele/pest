PHP_ARG_ENABLE([pest-observer],
  [whether to enable the experimental Pest Zend observer],
  [AS_HELP_STRING([--enable-pest-observer], [Native per-test PHP file observation])],
  [no])
if test "$PHP_PEST_OBSERVER" != "no"; then
  PHP_NEW_EXTENSION([pest_observer], [pest_observer.c], [$ext_shared])
fi
