#!/bin/bash

which drush

mkdir -p ./dripyard_neonbyte_default_content/content/menu_link_content/

# Use pipe delimiter to avoid issues with spaces or tabs in titles
drush sqlq "SELECT id, title FROM menu_link_content_data;" | while IFS=$'\t' read -r id title; do
    # Skip if ID is empty or not numeric
    if [[ -z "$id" || ! "$id" =~ ^[0-9]+$ ]]; then
        continue
    fi

    # Trim leading/trailing whitespace from title
    title=$(echo "$title" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')

    # Clean the title to make it a safe filename
    safe_title=$(echo "$title" | tr '[:upper:]' '[:lower:]')             # lowercase
    safe_title=$(echo "$safe_title" | sed 's/ /_/g')                     # spaces to underscores
    safe_title=$(echo "$safe_title" | tr -cd '[:alnum:]_-')              # remove other special chars

    # Handle empty titles
    if [[ -z "$safe_title" ]]; then
        safe_title="menu_link_$id"
    fi

    # Export the config
    echo "Exporting menu_link_content $id to ${safe_title}.yml"
    drush dce menu_link_content "$id" > "./dripyard_neonbyte_default_content/content/menu_link_content/${id}-${safe_title}.yml"

done
