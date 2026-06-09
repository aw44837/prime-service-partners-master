# Dripyard Base Recipe

Includes all recipies required to build dripyard based themes.

## Composer Install

Fetch the repo to a path like ./recipes/dripyard_base

Add the recipes/* path to composer as a repository

Make sure an installer path is defined like `"recipes/{$name}": ["type:drupal-recipe"],`

`composer require dripyard/dripyard_base`

## Manual Install

Fetch the repository to your recipies folder in Drupal and run the following:

`drush recipes $(pwd)/recipes/dripyard_base_recipe/[recipe_name]`
