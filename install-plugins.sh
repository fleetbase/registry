#!/bin/bash

# Save the current working directory
current_dir=$(pwd)

# Navigate to the directory containing the plugins
cd "$current_dir/plugins"

# Find all child directories and run npm install if package.json exists
for dir in */; do
    if [[ -f "${dir}package.json" ]]; then
        echo "Running npm install in $dir"
        cd "$dir"
        npm install --no-bin-links --install-strategy=shallow --omit=optional
        cd "$current_dir/plugins"
    else
        echo "No package.json found in $dir, skipping..."
    fi
done

cd "$current_dir"
echo "npm install completed for all verdaccio plugins."