<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * ExamplePlugin - DAoC CMS
 *
 * Example plugin showing how to register hooks correctly.
 * This plugin adds a link to the sidebar and a
 * CSS import into the head.
 */
class ExamplePlugin implements PluginInterface
{
    private \PDO $db;
    private int  $userPriv;
    private int  $currentUserId;

    public static function getMetadata(): array
    {
        return [
            'slug'         => 'example_plugin',
            'name'         => 'Example Plugin',
            'version'      => '1.0.0',
            'author'       => 'Seraltos',
            'website'      => 'https://github.com/Darku11/daoc_cms',
            'description'  => 'Shows how hooks are registered correctly.',
            'dependencies' => [],
            'min_priv'     => 1,
        ];
    }

    public function __construct(\PDO $db, int $userPriv, int $currentUserId)
    {
        $this->db            = $db;
        $this->userPriv      = $userPriv;
        $this->currentUserId = $currentUserId;
    }

    public function initialize(): void
    {
    }

    public function registerHooks(): void
    {
        cms_register_hook('hook_sidebar_nav', function() {
            return '
                <h3><i class="fas fa-puzzle-piece"></i> Example</h3>
                <ul>
                    <li>
                        <a href="?p=example_plugin">
                            <i class="fas fa-star"></i> My Feature
                        </a>
                    </li>
                </ul>
            ';
        });

        cms_register_hook('hook_head', function() {
            return '<link rel="stylesheet" href="https://cdn.example.com/style.css">';
        });

        cms_register_hook('hook_footer', function() {
            return '<div style="text-align:center; padding:5px; font-size:10px; color:#333;">Powered by Example Plugin</div>';
        });
    }

    public function uninstall(): bool
    {
        return true;
    }

    public function render(): string
    {
        return '
            <div class="um-nexus-wrapper">
                <h2 style="font-family:Cinzel; color:#c5a059;">Example Plugin</h2>
                <p style="color:#666;">This plugin is an example.</p>
            </div>
        ';
    }
}
