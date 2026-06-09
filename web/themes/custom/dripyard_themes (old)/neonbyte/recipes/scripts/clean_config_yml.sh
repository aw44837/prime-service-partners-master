#!/bin/bash

DIR="${1:-.}"

# First: Clean file contents
find "$DIR" -type f -name "*.yml" | while IFS= read -r file; do
  echo "Processing content: $file"

  tmp_file=$(mktemp)

  awk '
    BEGIN { in_core_block = 0 }
    # Detect start of _core block
    /^_core:$/ {
      in_core_block = 1
      next
    }
    # Detect indented lines within _core block
    in_core_block && /^[[:space:]]+[^[:space:]]/ {
      if ($1 == "default_config_hash:") next
      # If more lines under _core need to be skipped, add logic here
      next
    }
    # If a non-indented line appears, end the block
    in_core_block && !/^[[:space:]]+/ {
      in_core_block = 0
    }
    !in_core_block {
      print
    }
  ' "$file" | sed -E '
    # Remove lines with uuid:
    /^\s*uuid:\s*/d

    # Rewrite field_name: field_dripyard_xyz → field_name: dripyard_xyz
    s/^(\s*field_name:\s*)field_dripyard_/\1dripyard_/g

    # Rewrite field_name: field_dy_xyz → field_name: dripyard_xyz
    s/^(\s*field_name:\s*)field_dy_/\1dripyard_/g

    # Replace all instances of field_dripyard_ → dripyard_
    s/\bfield_dripyard_/dripyard_/g

    # Replace all instances of field_dy_ → dripyard_
    s/\bfield_dy_/dripyard_/g
  ' > "$tmp_file"

  mv "$tmp_file" "$file"
done

# Then: Rename files ONLY if filename includes ".field_"
find "$DIR" -type f -name "*.field_*.yml" | while IFS= read -r file; do
  new_name=$(echo "$file" | sed 's/\.field_/./g')

  if [ "$file" != "$new_name" ]; then
    echo "Renaming file: $file → $new_name"
    mv "$file" "$new_name"
  fi
done

# Reset any changes to image styles as they have different rules.
git checkout *image.style*yml
