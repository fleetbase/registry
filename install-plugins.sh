#!/bin/bash

# Save the current working directory
current_dir=$(pwd)

# Navigate to the directory containing the plugins
cd ./plugins

# Find all child directories and run npm install if package.json exists
for dir in */; do
    if [[ -f "${dir}package.json" ]]; then
        echo "Running npm install in $dir"
        cd "$dir"
        npm install
        cd "$current_dir"
    else
        echo "No package.json found in $dir, skipping..."
    fi
done

echo "npm install completed for all verdaccio plugins."