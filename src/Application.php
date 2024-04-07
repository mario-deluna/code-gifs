<?php 

namespace App;

use Error;
use GL\Math\Vec2;
use GL\Math\Vec4;
use GL\VectorGraphics\{VGAlign, VGColor, VGContext};
use Tempest\Highlight\Languages\Php\PhpLanguage;
use Tempest\Highlight\Theme;
use Tempest\Highlight\Themes\LightTerminalTheme;
use Tempest\Highlight\Tokens\GroupTokens;
use Tempest\Highlight\Tokens\ParseTokens;
use Tempest\Highlight\Tokens\Token;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;
use VISU\Graphics\{RenderTarget, Viewport, Camera, CameraProjectionMode};
use VISU\Graphics\Rendering\RenderContext;
use VISU\Geo\Transform;
use VISU\OS\{InputActionMap, Key};

use VISU\Quickstart\QuickstartApp;
use VISU\Quickstart\Render\QuickstartDebugMetricsOverlay;

enum FormattingTokenType: string implements TokenType
{
    case SPACE = 'space';
    case TAB = 'tab';
    case NEWLINE = 'newline';
    case OTHER = 'other';

    public function getValue() : string  {
        return $this->value;
    }

    public function canContain(TokenType $other) : bool {
        return false;
    }
}


class Application extends QuickstartApp
{   
    /**
     * @var array<string, VGColor>
     */
    private array $colorTokenMap = [];

    /**
     * @var array<Token>
     */
    private array $tokens = [];

    /**
     * The current code render size
     * Do not modify this value directly, its updated by the setCodeToRender method
     */
    private Vec2 $codeRenderedSize;

    /**
     * The font size of the text
     */
    private int $fontSize = 20;

    /**
     * The line height of the text
     */
    private float $lineHeight = 1.5;

    /**
     * Padding around the code
     */
    public float $padding = 50;

    /**
     * A function that is invoked once the app is ready to run.
     * This happens exactly just before the game loop starts.
     * 
     * Here you can prepare your game state, register services, callbacks etc.
     */
    public function ready() : void
    {
        parent::ready();

        // You can bind actions to keys in VISU 
        // this way you can decouple your game logic from the actual key bindings
        // and provides a comfortable way to access input state
        $actions = new InputActionMap;
        $actions->bindButton('reset', Key::R);

        $this->inputContext->registerAndActivate('main', $actions);

        // load the inconsolata font to display the current score
        if ($this->vg->createFont('inconsolata', VISU_PATH_FRAMEWORK_RESOURCES_FONT . '/inconsolata/Inconsolata-Regular.ttf') === -1) {
            throw new Error('Inconsolata font could not be loaded.');
        }

        // load the color theme
        $this->loadThemeGithubDarkDefault();

        // load the code to render
        $code = file_get_contents(VISU_PATH_ROOT . '/input.txt');
        $this->setCodeToRender($code);
    }

    /**
     * Sets the code which should be rendered
     */
    public function setCodeToRender(string $code) 
    {
        $parsedTokens = (new ParseTokens())($code, new PhpLanguage);
        $groupedTokens = (new GroupTokens())($parsedTokens);

        $tokens = [];

        $lastOffset = 0;
        foreach ($groupedTokens as $group) {
            $offset = $group->start;
            $length = $offset - $lastOffset;
            if ($length > 0) {
                $content = substr($code, $lastOffset, $length);

                // ensure linebreaks to be split seperately
                $content = str_replace("\r\n", "\n", $content);
                $parts = explode("\n", $content);

                foreach ($parts as $i => $part) {
                    if ($i > 0) $tokens[] = new Token($offset, "\n", FormattingTokenType::NEWLINE);

                    // if the trimmed part is empty, it was one or more spaces
                    if (strlen($part) === 0) {
                    } elseif (trim($part) === '') {
                        $tokens[] = new Token($offset, $part, FormattingTokenType::SPACE);
                    } else {
                        $tokens[] = new Token($offset, $part, FormattingTokenType::OTHER);
                    }
                }
            }
            $tokens[] = $group;
            $lastOffset = $group->end;
        }

        // add the remaining part of the code
        $content = substr($code, $lastOffset);
        $tokens[] = new Token($lastOffset, $content, FormattingTokenType::OTHER);

        $this->tokens = $tokens;

        // also udpate the size of the viewport
        $this->codeRenderedSize = $this->getFrameForText();
    }

    public function loadThemeGithubDarkDefault() 
    {
        $this->colorTokenMap = [
            FormattingTokenType::OTHER->value => VGColor::white(),
            TokenTypeEnum::COMMENT->value => VGColor::hex('#8b949e'),
            TokenTypeEnum::KEYWORD->value => VGColor::hex('#ff7b72'),
            TokenTypeEnum::PROPERTY->value => VGColor::hex('#d2a8ff'),
            TokenTypeEnum::ATTRIBUTE->value => VGColor::hex('#d2a8ff'),
            TokenTypeEnum::TYPE->value => VGColor::hex('#EA4334'),
            TokenTypeEnum::GENERIC->value => VGColor::hex('#9d3af6'),
            TokenTypeEnum::VALUE->value => VGColor::hex('#a5d6ff'),
            TokenTypeEnum::VARIABLE->value => VGColor::hex('#ffa657'),
            TokenTypeEnum::OPERATOR->value => VGColor::gray(),
        ];
    }

    private function alphaEaseOutBounce(float $progress) : float
    {
        if ($progress < 1.0 / 2.75) {
            return 7.5625 * $progress * $progress;
        } else if ($progress < 2.0 / 2.75) {
            $progress -= 1.5 / 2.75;
            return 7.5625 * $progress * $progress + 0.75;
        } else if ($progress < 2.5 / 2.75) {
            $progress -= 2.25 / 2.75;
            return 7.5625 * $progress * $progress + 0.9375;
        } else {
            $progress -= 2.625 / 2.75;
            return 7.5625 * $progress * $progress + 0.984375;
        }
    }

    private function alphaEaseOut(float $progress) : float
    {
        return 1.0 - (1.0 - $progress) * (1.0 - $progress);
    }

    /**
     * Draw the scene. (You most definetly want to use this)
     * 
     * This is called from within the Quickstart render pass where the pipeline is already
     * prepared, a VG frame is also already started.
     */
    public function draw(RenderContext $context, RenderTarget $renderTarget) : void
    {
        // clear the screen
        $renderTarget->framebuffer()->clear(GL_COLOR_BUFFER_BIT | GL_STENCIL_BUFFER_BIT);

        $this->drawHighlightedCode($this->tickIndex, $renderTarget);
    }

    /**
     * Returns the required size for the given text.
     * @return Vec2 
     */
    public function getFrameForText() : Vec2 
    {
        $x = 0;
        $y = 0;

        $this->vg->fontSize($this->fontSize);
        $this->vg->textAlign(VGAlign::LEFT | VGAlign::TOP);
        $maxX = 0;

        foreach($this->tokens as $token) {
            if ($token->type === FormattingTokenType::NEWLINE) {
                $y += $this->fontSize * $this->lineHeight;
                $x = 0;
                continue;
            } elseif ($token->type === FormattingTokenType::SPACE) {
                $x += strlen($token->value) * 10;
                continue;
            }

            $bounds = new Vec4();
            $width = $this->vg->textBounds($x, $y, $token->value, $bounds);
            $maxX = max($maxX, $x + $width);
            $x += $width;
        }

        return new Vec2($maxX, $y + $this->fontSize * $this->lineHeight);
    }

    /**
     * Draws the code with highlighted tokens at the given tick
     */
    public function drawHighlightedCode(int $tick, RenderTarget $renderTarget) : void
    {
        $screenWidth = $renderTarget->width() / $renderTarget->contentScaleX;
        $screenHeight = $renderTarget->height() / $renderTarget->contentScaleY;

        // draw a window around the code
        $headerSize = 30;
        $codeSize = $this->codeRenderedSize;
        $windowSize = new Vec2($codeSize->x + 2 * $this->padding, $headerSize + $codeSize->y + 2 * $this->padding);
        
        // position the window in the center of the screen
        $windowPos = new Vec2(($screenWidth - $windowSize->x) / 2, ($screenHeight - $windowSize->y) / 2);

        // render a background first (Note it would be better to just clear in the proper color, but for the sake of the example we draw a rect here)
        $this->vg->beginPath();
        $this->vg->fillColor(VGColor::white());
        $this->vg->rect(0, 0, $renderTarget->width(), $renderTarget->height());
        $this->vg->fill();

        // render the window background
        $this->vg->beginPath();
        $this->vg->fillColor(new VGColor(0.082, 0.09, 0.094, 1.0));
        $this->vg->roundedRect($windowPos->x, $windowPos->y, $windowSize->x, $windowSize->y, 25);
        $this->vg->fill();

        // render the macos style window header
        $windowActionColors = [
            VGColor::hex('#ff5f56'),
            VGColor::hex('#ffbd2e'),
            VGColor::hex('#27c93f')
        ];
        for ($i = 0; $i < 3; $i++) {
            $this->vg->beginPath();
            $this->vg->fillColor($windowActionColors[$i]);
            $this->vg->circle($windowPos->x + 30 + 22 * $i, $windowPos->y + 30, 7);
            $this->vg->fill();
        }

        // 0.25 seconds per token
        $ticksPerToken = 15;
        $tokenAnimationIndex = (int) ($tick / $ticksPerToken);
        $tokenProgress = ($tick % $ticksPerToken) / $ticksPerToken;

        // define the starting positions
        $baseX = $windowPos->x + $this->padding;
        $baseY = $windowPos->y + $this->padding + $headerSize;
        $fontSize = 20;
        $lineHeight = 1.5;

        $x = $baseX;
        $y = $baseY;

        $this->vg->fillColor(VGColor::white());
        $this->vg->fontSize($fontSize);
        $this->vg->textAlign(VGAlign::LEFT | VGAlign::TOP);

        $currentTokenIndex = 0;
        foreach($this->tokens as $token) {
            if ($token->type === FormattingTokenType::NEWLINE) {
                $y += $fontSize * $lineHeight;
                $x = $baseX;
                continue;
            } elseif ($token->type === FormattingTokenType::SPACE) {
                $x += strlen($token->value) * 10;
                continue;
            }
             
            if ($currentTokenIndex > $tokenAnimationIndex) {
                break;
            }

            $color = $this->colorTokenMap[$token->type->getValue()];

            if ($currentTokenIndex === $tokenAnimationIndex) {
                // move from right to left
                $x += 20 * (1 - $this->alphaEaseOut($tokenProgress));

                // move from bottom to top
                $y += 50 * (1 - $this->alphaEaseOut($tokenProgress));

                // fade in
                $color = new VGColor($color->r, $color->g, $color->b, $this->alphaEaseOut($tokenProgress));
            }
            
            $this->vg->fillColor($color);
            $x = $this->vg->text($x, $y, $token->value ?? '');

            $currentTokenIndex++;
        }
    }

    /**
     * Update the games state
     * This method might be called multiple times per frame, or not at all if
     * the frame rate is very high.
     * 
     * The update method should step the game forward in time, this is the place
     * where you would update the position of your game objects, check for collisions
     * and so on. 
     */
    public function update() : void
    {
        parent::update();
    }
}