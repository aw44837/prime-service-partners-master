#!/bin/bash

set -e

echo "WARNING: This operation will DELETE ALL YOUR DATA and reinstall Drupal for each recipe found."
read -p "Are you absolutely sure you want to continue? (type yes to continue): " confirm

if [[ "$confirm" != "yes" ]]; then
  echo "Aborted."
  exit 1
fi

# Find all recipe.yml files starting from the current directory
find . -type f -name "recipe.yml" | while read -r recipe_file; do
  # Strip leading ./ if present
  recipe_dir=$(dirname "$recipe_file" | sed 's|^\./||')

  echo "Processing recipe in: $recipe_dir with 'drush recipe ../recipes/dripyard_base/$recipe_dir'"

  drush si minimal -y
  drush recipe "../recipes/dripyard_base/$recipe_dir"
done
