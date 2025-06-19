#!/bin/bash

# Script to create your first version tag

echo "Creating first version tag for Proofgen Redux..."

# Ensure we're on the main branch
current_branch=$(git branch --show-current)
if [ "$current_branch" != "main" ]; then
    echo "Error: You must be on the main branch to create a tag"
    echo "Current branch: $current_branch"
    exit 1
fi

# Check for uncommitted changes
if ! git diff-index --quiet HEAD --; then
    echo "Error: You have uncommitted changes. Please commit or stash them first."
    exit 1
fi

# Create the first version tag
TAG="v1.0.0"
echo "Creating tag: $TAG"

# Create annotated tag
git tag -a "$TAG" -m "Initial release of Proofgen Redux with update system"

echo "Tag created successfully!"
echo ""
echo "To push the tag to remote, run:"
echo "  git push origin $TAG"
echo ""
echo "After pushing, the update system will be able to detect this version."