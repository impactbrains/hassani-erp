/*
 * "Start Hassani ERP.exe" - a tiny launcher so the shop can double-click an
 * .exe with the Hassani icon. It only runs starter\start.ps1 (next to this
 * file) in a PowerShell window; all the logic lives in that readable script.
 *
 * Build (from Linux or Windows, with zig):
 *   zig cc -target x86_64-windows-gnu -O2 launcher.c launcher.rc -o "Start Hassani ERP.exe"
 */
#define WIN32_LEAN_AND_MEAN
#include <windows.h>
#include <wchar.h>

int WINAPI wWinMain(HINSTANCE inst, HINSTANCE prev, PWSTR args, int show)
{
    wchar_t exe[MAX_PATH];
    wchar_t dir[MAX_PATH];
    wchar_t script[MAX_PATH + 64];
    wchar_t cmd[MAX_PATH * 2 + 256];
    STARTUPINFOW si;
    PROCESS_INFORMATION pi;
    wchar_t *slash;

    (void)inst; (void)prev; (void)args; (void)show;

    if (!GetModuleFileNameW(NULL, exe, MAX_PATH)) return 1;
    wcscpy(dir, exe);
    slash = wcsrchr(dir, L'\\');
    if (slash) *slash = L'\0';

    swprintf(script, sizeof(script) / sizeof(script[0]), L"%ls\\starter\\start.ps1", dir);
    if (GetFileAttributesW(script) == INVALID_FILE_ATTRIBUTES) {
        MessageBoxW(NULL, L"starter\\start.ps1 was not found next to this program.\nKeep \"Start Hassani ERP.exe\" in the main Hassani ERP folder.", L"Hassani ERP", MB_ICONERROR | MB_OK);
        return 1;
    }

    swprintf(cmd, sizeof(cmd) / sizeof(cmd[0]), L"powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"%ls\"", script);

    ZeroMemory(&si, sizeof(si));
    si.cb = sizeof(si);
    ZeroMemory(&pi, sizeof(pi));
    if (!CreateProcessW(NULL, cmd, NULL, NULL, FALSE, CREATE_NEW_CONSOLE, NULL, dir, &si, &pi)) {
        MessageBoxW(NULL, L"Could not start Windows PowerShell.", L"Hassani ERP", MB_ICONERROR | MB_OK);
        return 1;
    }
    CloseHandle(pi.hThread);
    CloseHandle(pi.hProcess);
    return 0;
}
