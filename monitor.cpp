#include <iostream>
#include <vector>
#include <chrono>
#include <thread>
#include <string>
#include <iomanip>
#include <algorithm>
#include <fstream>
#include <sstream>
#include <cctype>
#include <cmath>
#include <limits>
#include <cstring>
#include <mutex>
#include <atomic>
#include <csignal>

#ifdef _WIN32
#include <windows.h>
#include <psapi.h>
#include <tlhelp32.h>
#else
#include <unistd.h>
#include <dirent.h>
#include <sys/sysinfo.h>
#include <sys/types.h>
#include <sys/stat.h>
#include <fcntl.h>
#endif

std::atomic<bool> stop_monitoring(false);

void signal_handler(int signal) {
    stop_monitoring = true;
}

class ProcessMonitor {
public:
    ProcessMonitor(int pid) : pid(pid) {
        get_process_name();
        get_system_info();
        initialize_history();
    }

    void start_monitoring() {
        std::signal(SIGINT, signal_handler);
        
        #ifdef _WIN32
        HANDLE hConsole = GetStdHandle(STD_OUTPUT_HANDLE);
        CONSOLE_CURSOR_INFO cursorInfo;
        GetConsoleCursorInfo(hConsole, &cursorInfo);
        cursorInfo.bVisible = false;
        SetConsoleCursorInfo(hConsole, &cursorInfo);
        #else
        std::cout << "\033[?25l"; // Hide cursor
        #endif

        while (!stop_monitoring) {
            update_console();
            std::this_thread::sleep_for(std::chrono::seconds(1));
        }

        #ifdef _WIN32
        cursorInfo.bVisible = true;
        SetConsoleCursorInfo(hConsole, &cursorInfo);
        #else
        std::cout << "\033[?25h"; // Show cursor
        #endif
    }

private:
    int pid;
    std::string process_name;
    int num_cores = 1;
    double total_ram = 0;
    
    // History buffers
    std::vector<double> cpu_history;
    std::vector<double> mem_percent_history;
    std::vector<double> mem_mb_history;
    std::vector<int> thread_count_history;
    
    // For CPU calculation
    double last_cpu_time = 0;
    std::chrono::steady_clock::time_point last_update_time;
    
    void initialize_history() {
        cpu_history = std::vector<double>(60, 0);
        mem_percent_history = std::vector<double>(60, 0);
        mem_mb_history = std::vector<double>(60, 0);
        thread_count_history = std::vector<int>(60, 0);
        last_update_time = std::chrono::steady_clock::now();
    }

    void get_system_info() {
        #ifdef _WIN32
        SYSTEM_INFO sysInfo;
        GetSystemInfo(&sysInfo);
        num_cores = sysInfo.dwNumberOfProcessors;
        
        MEMORYSTATUSEX memInfo;
        memInfo.dwLength = sizeof(memInfo);
        GlobalMemoryStatusEx(&memInfo);
        total_ram = static_cast<double>(memInfo.ullTotalPhys) / (1024 * 1024);
        #else
        num_cores = sysconf(_SC_NPROCESSORS_ONLN);
        
        struct sysinfo memInfo;
        sysinfo(&memInfo);
        total_ram = static_cast<double>(memInfo.totalram) / (1024 * 1024);
        #endif
    }

    void get_process_name() {
        #ifdef _WIN32
        HANDLE hProcess = OpenProcess(PROCESS_QUERY_INFORMATION | PROCESS_VM_READ, FALSE, pid);
        if (hProcess) {
            char name[MAX_PATH];
            if (GetModuleBaseNameA(hProcess, NULL, name, MAX_PATH)) {
                process_name = name;
            }
            CloseHandle(hProcess);
        }
        #else
        std::ifstream cmdline("/proc/" + std::to_string(pid) + "/cmdline");
        if (cmdline) {
            std::getline(cmdline, process_name);
            // Remove null characters
            process_name.erase(std::remove(process_name.begin(), process_name.end(), '\0'), process_name.end());
            if (process_name.empty()) {
                std::ifstream status("/proc/" + std::to_string(pid) + "/status");
                if (status) {
                    std::string line;
                    while (std::getline(status, line)) {
                        if (line.find("Name:") == 0) {
                            process_name = line.substr(6);
                            // Trim whitespace
                            process_name.erase(0, process_name.find_first_not_of(" \t"));
                            process_name.erase(process_name.find_last_not_of(" \t") + 1);
                            break;
                        }
                    }
                }
            }
        }
        #endif
        if (process_name.empty()) process_name = "Unknown Process";
    }

    double get_cpu_time() {
        #ifdef _WIN32
        HANDLE hProcess = OpenProcess(PROCESS_QUERY_INFORMATION | PROCESS_VM_READ, FALSE, pid);
        if (!hProcess) return 0.0;
        
        FILETIME createTime, exitTime, kernelTime, userTime;
        if (!GetProcessTimes(hProcess, &createTime, &exitTime, &kernelTime, &userTime)) {
            CloseHandle(hProcess);
            return 0.0;
        }
        
        ULARGE_INTEGER kernel, user;
        kernel.LowPart = kernelTime.dwLowDateTime;
        kernel.HighPart = kernelTime.dwHighDateTime;
        user.LowPart = userTime.dwLowDateTime;
        user.HighPart = userTime.dwHighDateTime;
        
        CloseHandle(hProcess);
        return (static_cast<double>(kernel.QuadPart) + static_cast<double>(user.QuadPart)) / 10000000.0; // Convert to seconds
        #else
        std::ifstream stat("/proc/" + std::to_string(pid) + "/stat");
        if (!stat) return 0.0;
        
        std::string line;
        std::getline(stat, line);
        std::istringstream iss(line);
        std::string token;
        for (int i = 1; i <= 13; i++) std::getline(iss, token, ' ');
        std::getline(iss, token, ' '); // utime
        long utime = std::stol(token);
        std::getline(iss, token, ' '); // stime
        long stime = std::stol(token);
        
        long clock_ticks = sysconf(_SC_CLK_TCK);
        return (utime + stime) / static_cast<double>(clock_ticks);
        #endif
    }

    double get_memory_usage_mb() {
        #ifdef _WIN32
        HANDLE hProcess = OpenProcess(PROCESS_QUERY_INFORMATION | PROCESS_VM_READ, FALSE, pid);
        if (!hProcess) return 0.0;
        
        PROCESS_MEMORY_COUNTERS pmc;
        if (!GetProcessMemoryInfo(hProcess, &pmc, sizeof(pmc))) {
            CloseHandle(hProcess);
            return 0.0;
        }
        
        CloseHandle(hProcess);
        return static_cast<double>(pmc.WorkingSetSize) / (1024 * 1024);
        #else
        std::ifstream statm("/proc/" + std::to_string(pid) + "/statm");
        if (!statm) return 0.0;
        
        long size;
        statm >> size;
        return size * sysconf(_SC_PAGESIZE) / (1024.0 * 1024.0);
        #endif
    }

    double get_memory_usage_percent() {
        double mem_mb = get_memory_usage_mb();
        return (mem_mb / total_ram) * 100.0;
    }

    int get_thread_count() {
        #ifdef _WIN32
        HANDLE hSnapshot = CreateToolhelp32Snapshot(TH32CS_SNAPTHREAD, 0);
        if (hSnapshot == INVALID_HANDLE_VALUE) return 0;
        
        THREADENTRY32 te32;
        te32.dwSize = sizeof(THREADENTRY32);
        
        int count = 0;
        if (Thread32First(hSnapshot, &te32)) {
            do {
                if (te32.th32OwnerProcessID == pid) {
                    count++;
                }
            } while (Thread32Next(hSnapshot, &te32));
        }
        CloseHandle(hSnapshot);
        return count;
        #else
        std::ifstream task("/proc/" + std::to_string(pid) + "/task");
        if (!task) return 0;
        
        int count = 0;
        std::string tid;
        while (std::getline(task, tid)) {
            if (!tid.empty() && std::all_of(tid.begin(), tid.end(), ::isdigit)) {
                count++;
            }
        }
        return count;
        #endif
    }

    void update_metrics() {
        auto now = std::chrono::steady_clock::now();
        double elapsed = std::chrono::duration<double>(now - last_update_time).count();
        last_update_time = now;
        
        double current_cpu_time = get_cpu_time();
        double cpu_usage = 0.0;
        
        if (last_cpu_time > 0 && elapsed > 0) {
            cpu_usage = ((current_cpu_time - last_cpu_time) / elapsed) * 100.0;
            // Adjust for multi-core systems
            cpu_usage /= num_cores;
        }
        last_cpu_time = current_cpu_time;
        
        double mem_percent = get_memory_usage_percent();
        double mem_mb = get_memory_usage_mb();
        int threads = get_thread_count();
        
        // Update history (shift left)
        std::rotate(cpu_history.begin(), cpu_history.begin() + 1, cpu_history.end());
        std::rotate(mem_percent_history.begin(), mem_percent_history.begin() + 1, mem_percent_history.end());
        std::rotate(mem_mb_history.begin(), mem_mb_history.begin() + 1, mem_mb_history.end());
        std::rotate(thread_count_history.begin(), thread_count_history.begin() + 1, thread_count_history.end());
        
        // Store new values at the end
        cpu_history.back() = cpu_usage;
        mem_percent_history.back() = mem_percent;
        mem_mb_history.back() = mem_mb;
        thread_count_history.back() = threads;
    }

    void update_console() {
        update_metrics();
        
        #ifdef _WIN32
        system("cls");
        #else
        std::cout << "\033[2J\033[1;1H"; // Clear screen and move to top-left
        #endif
        
        // Header
        std::cout << "==================================================\n";
        std::cout << " Process Resource Monitor: " << process_name << " (PID: " << pid << ")\n";
        std::cout << "==================================================\n\n";
        
        // CPU Usage
        draw_metric("CPU Usage", cpu_history, "%", 100.0);
        
        // Memory Percentage
        draw_metric("Memory Usage", mem_percent_history, "%", 100.0);
        
        // Memory MB
        double max_mem = *std::max_element(mem_mb_history.begin(), mem_mb_history.end());
        if (max_mem < 10) max_mem = 100; // Default max if no data
        draw_metric("Memory Usage", mem_mb_history, "MB", max_mem);
        
        // Thread Count
        int max_threads = *std::max_element(thread_count_history.begin(), thread_count_history.end());
        if (max_threads < 5) max_threads = 10; // Default max if no data
        draw_metric("Thread Count", thread_count_history, "", static_cast<double>(max_threads));
        
        // Footer
        std::cout << "\nPress Ctrl+C to stop monitoring\n";
        std::cout.flush();
    }

    template <typename T>
    void draw_metric(const std::string& title, const std::vector<T>& history, 
                     const std::string& unit, double max_val) {
        T current = history.back();
        std::cout << title << ": " << std::fixed << std::setprecision(2) << current << " " << unit << "\n";
        
        // Current value bar
        int bar_width = 50;
        int fill = static_cast<int>((current / max_val) * bar_width);
        fill = std::min(fill, bar_width);
        
        std::cout << "[" << std::string(fill, '=') << std::string(bar_width - fill, ' ') << "]\n";
        
        // History graph
        std::cout << "History: ";
        T min_val = *std::min_element(history.begin(), history.end());
        T range = max_val - min_val;
        if (range == 0) range = 1;
        
        for (const T& val : history) {
            int height = static_cast<int>(((val - min_val) / range) * 5);
            std::cout << " .-*#%@"[std::min(height, 5)];
        }
        std::cout << "\n\n";
    }
};

int main(int argc, char* argv[]) {
    int pid = 0;
    
    if (argc > 1) {
        pid = std::stoi(argv[1]);
    } else {
        #ifdef _WIN32
        HANDLE hSnapshot = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);
        if (hSnapshot == INVALID_HANDLE_VALUE) {
            std::cerr << "Error: Could not create process snapshot" << std::endl;
            return 1;
        }
        
        PROCESSENTRY32 pe32;
        pe32.dwSize = sizeof(PROCESSENTRY32);
        
        std::cout << "Running processes:\n";
        if (Process32First(hSnapshot, &pe32)) {
            int count = 0;
            do {
                std::wcout << "  " << pe32.th32ProcessID << " - " << pe32.szExeFile << "\n";
                if (++count >= 20) {
                    std::cout << "... (more processes not shown)\n";
                    break;
                }
            } while (Process32Next(hSnapshot, &pe32));
        }
        CloseHandle(hSnapshot);
        #else
        DIR* dir = opendir("/proc");
        if (!dir) {
            std::cerr << "Error: Could not open /proc directory" << std::endl;
            return 1;
        }
        
        std::cout << "Running processes:\n";
        int count = 0;
        struct dirent* entry;
        while ((entry = readdir(dir)) != nullptr && count < 20) {
            if (entry->d_type == DT_DIR) {
                char* end;
                long pid = std::strtol(entry->d_name, &end, 10);
                if (*end == '\0') {
                    std::ifstream cmdline(std::string("/proc/") + entry->d_name + "/cmdline");
                    std::string name;
                    if (cmdline) {
                        std::getline(cmdline, name);
                        if (!name.empty()) {
                            name.erase(std::remove(name.begin(), name.end(), '\0'), name.end());
                            std::cout << "  " << pid << " - " << name << "\n";
                            count++;
                        }
                    }
                }
            }
        }
        closedir(dir);
        if (count == 20) std::cout << "... (more processes not shown)\n";
        #endif
        
        std::cout << "\nEnter PID to monitor: ";
        std::cin >> pid;
    }
    
    try {
        ProcessMonitor monitor(pid);
        monitor.start_monitoring();
    } catch (const std::exception& e) {
        std::cerr << "Error: " << e.what() << std::endl;
        return 1;
    }
    
    return 0;
}