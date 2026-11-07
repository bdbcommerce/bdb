<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\McpSetting;
use App\Services\Mcp\TeamTools;
use App\Services\Seo\SeoFiles;
use Illuminate\Http\Request;

/** Admin → AI Assistant → MCP server. */
class McpSettingController extends Controller
{
    public function edit()
    {
        return view('admin.mcp.edit', [
            'settings' => McpSetting::current(),
            'tools' => McpSetting::TOOLS,
            'teamTools' => TeamTools::TOOLS,
            'endpoint' => SeoFiles::publicUrl(route('mcp')),
            'publicHttps' => str_starts_with((string) config('app.url'), 'https://'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'require_token' => ['boolean'],
            'rate_limit' => ['required', 'integer', 'min:5', 'max:600'],
            'tools' => ['array'],
            'tools.*' => ['boolean'],
            'autopublish' => ['boolean'],
        ]);

        $settings = McpSetting::current();
        $tools = [];
        foreach ([...array_keys(McpSetting::TOOLS), ...array_keys(TeamTools::TOOLS)] as $tool) {
            $tools[$tool] = (bool) ($data['tools'][$tool] ?? false);
        }
        $settings->update([
            'enabled' => $request->boolean('enabled'),
            'require_token' => $request->boolean('require_token'),
            'rate_limit' => (int) $data['rate_limit'],
            'tools' => $tools,
            'autopublish' => $request->boolean('autopublish'),
        ]);

        // Turning on token mode without a token: create one now (shown once).
        if ($settings->enabled && $settings->require_token && ! $settings->token_hash) {
            return redirect()->route('admin.mcp.edit')->with('success', 'MCP settings saved.')->with('mcp_token', $settings->regenerateToken());
        }

        return redirect()->route('admin.mcp.edit')->with('success', 'MCP settings saved.');
    }

    public function token()
    {
        $token = McpSetting::current()->regenerateToken();

        return redirect()->route('admin.mcp.edit')
            ->with('success', 'New access token created. The old one no longer works.')
            ->with('mcp_token', $token);
    }
}
