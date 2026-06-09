#!/bin/bash

# Check if argument is provided
if [ -z "$1" ]; then
    echo "Usage: $0 <target-subdirectory>"
    exit 1
fi

TARGET_DIR="./$1/config"
SOURCE_DIR="../../config/sync"

# Ensure target directory exists
if [ ! -d "$TARGET_DIR" ]; then
    echo "Target directory '$TARGET_DIR' does not exist."
    exit 2
fi

# Loop through files in the target directory
for target_file in "$TARGET_DIR"/*; do
    # Get the filename only
    filename=$(basename "$target_file")

    # Check if file with the same name exists in the source directory
    if [ -f "$SOURCE_DIR/$filename" ]; then
        echo "Copying $SOURCE_DIR/$filename to $TARGET_DIR/"
        cp "$SOURCE_DIR/$filename" "$TARGET_DIR/"
    else
        echo "No match for $filename in $SOURCE_DIR"
    fi
done

# Execute the clean_config_yml.sh script
./scripts/clean_config_yml.sh > /dev/null 2>&1
