# Code Gifs

Generate a GIF from a code snippet using [Tempest Highlight](https://github.com/tempestphp/highlight) & [PHP-GLFW](https://github.com/mario-deluna/php-glfw).

This is the source code for the following [blog post](https://station.clancats.com/creating-animated-code-snippets-with-tempest-highlighting-and-php-glfw/).

![output](https://github.com/mario-deluna/code-gifs/assets/956212/225809ce-d92d-4910-ac71-d9594109825f)

## Installation

Note that this requires PHP > 8.1 and the [PHP-GLFW](https://github.com/mario-deluna/php-glfw) extension.

```bash
git clone git@github.com:mario-deluna/code-gifs.git
cd code-gifs
composer install
```

## Usage

There is a file in the project root called `input.txt` that contains the code snippet to be rendered. Modify this to your needs, and then run the following to preview the animation:

```bash
php bin/start.php
```

And run the following to produce an `mp4` and `gif` file:

```bash
php bin/render.php
```

Note, rendering to an `mp4` and/or `gif` requires `ffmpeg` to be installed.
