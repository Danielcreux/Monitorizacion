import psutil
import matplotlib.pyplot as plt
from matplotlib.animation import FuncAnimation
import time
import platform

class ProcessMonitor:
    def __init__(self, pid=None, max_points=60):
        self.max_points = max_points
        self.fig, self.axes = plt.subplots(2, 2, figsize=(15, 10))
        self.fig.suptitle('Process Resource Monitor', fontsize=16)
        plt.subplots_adjust(hspace=0.4, wspace=0.3)
        
        # Initialize data storage
        self.timestamps = []
        self.cpu_percents = []
        self.memory_percents = []
        self.memory_mb = []
        self.thread_counts = []
        self.process = None
        self.process_name = ""
        
        # Get PID from user if not provided
        if pid is None:
            pid = self.get_pid_from_user()
        
        # Find the process
        self.find_process(pid)
        
        # Initialize plots
        self.init_plots()
    
    def get_pid_from_user(self):
        """Prompt user to enter PID with validation"""
        while True:
            try:
                # Show running processes to help user choose
                print("\nCurrently running processes:")
                for i, proc in enumerate(sorted(psutil.process_iter(['name', 'pid']), key=lambda p: p.info['name'])):
                    if i < 20:  # Show first 20 to avoid overwhelming
                        print(f"{proc.info['pid']:>6} - {proc.info['name']}")
                    elif i == 20:
                        print("... (more processes not shown)")
                
                pid_input = input("\nEnter the PID of the process to monitor (or 'q' to quit): ")
                if pid_input.lower() == 'q':
                    raise KeyboardInterrupt()
                
                pid = int(pid_input)
                return pid
            except ValueError:
                print("Invalid input. Please enter a numeric PID or 'q' to quit.")
            except KeyboardInterrupt:
                print("\nExiting...")
                exit(0)
    
    def find_process(self, pid):
        try:
            self.process = psutil.Process(pid)
            self.process_name = self.process.name()
            print(f"\nMonitoring process: {self.process_name} (PID: {self.process.pid})")
            print("Press Ctrl+C in the terminal to stop monitoring.\n")
        except psutil.NoSuchProcess:
            raise ValueError(f"Process with PID {pid} not found. It may have terminated.")

    def init_plots(self):
        # CPU Usage Plot
        self.axes[0, 0].set_title(f'CPU Usage - {self.process_name} (%)')
        self.axes[0, 0].set_ylim(0, 100)
        self.cpu_line, = self.axes[0, 0].plot([], [], 'r-')
        self.axes[0, 0].set_xlabel('Time (s)')
        self.axes[0, 0].grid(True)
        
        # Memory Usage Percentage Plot
        self.axes[0, 1].set_title(f'Memory Usage - {self.process_name} (%)')
        self.axes[0, 1].set_ylim(0, 100)
        self.memory_percent_line, = self.axes[0, 1].plot([], [], 'b-')
        self.axes[0, 1].set_xlabel('Time (s)')
        self.axes[0, 1].grid(True)
        
        # Memory Usage in MB Plot
        self.axes[1, 0].set_title(f'Memory Usage - {self.process_name} (MB)')
        self.axes[1, 0].set_ylim(0, 100)  # Will auto-adjust
        self.memory_mb_line, = self.axes[1, 0].plot([], [], 'g-')
        self.axes[1, 0].set_xlabel('Time (s)')
        self.axes[1, 0].grid(True)
        
        # Thread Count Plot
        self.axes[1, 1].set_title(f'Thread Count - {self.process_name}')
        self.axes[1, 1].set_ylim(0, 100)  # Will auto-adjust
        self.thread_line, = self.axes[1, 1].plot([], [], 'm-')
        self.axes[1, 1].set_xlabel('Time (s)')
        self.axes[1, 1].grid(True)
        
    def update_data(self):
        try:
            # Get process metrics
            cpu_percent = self.process.cpu_percent(interval=1)
            memory_info = self.process.memory_info()
            memory_percent = self.process.memory_percent()
            memory_mb = memory_info.rss / (1024 * 1024)  # Convert to MB
            thread_count = self.process.num_threads()
            
            # Get current timestamp
            current_timestamp = time.time() - self.start_time
            
            # Store data
            self.timestamps.append(current_timestamp)
            self.cpu_percents.append(cpu_percent)
            self.memory_percents.append(memory_percent)
            self.memory_mb.append(memory_mb)
            self.thread_counts.append(thread_count)
            
            # Limit data points
            if len(self.timestamps) > self.max_points:
                self.timestamps.pop(0)
                self.cpu_percents.pop(0)
                self.memory_percents.pop(0)
                self.memory_mb.pop(0)
                self.thread_counts.pop(0)
            
            return True, current_timestamp, cpu_percent, memory_percent, memory_mb, thread_count
        
        except (psutil.NoSuchProcess, psutil.AccessDenied):
            return False, None, None, None, None, None
    
    def update_plots(self, frame):
        success, current_timestamp, cpu_percent, memory_percent, memory_mb, thread_count = self.update_data()
        
        if not success:
            print("Process no longer exists or access denied. Closing monitor.")
            plt.close()
            return []
        
        # Only update plots if we have data
        if len(self.timestamps) > 0:
            # Update CPU plot
            self.cpu_line.set_data(self.timestamps, self.cpu_percents)
            
            # Update Memory percentage plot
            self.memory_percent_line.set_data(self.timestamps, self.memory_percents)
            
            # Update Memory MB plot
            self.memory_mb_line.set_data(self.timestamps, self.memory_mb)
            self.axes[1, 0].set_ylim(0, max(10, max(self.memory_mb[-10:] or [0]) * 1.2))
            
            # Update Thread count plot
            self.thread_line.set_data(self.timestamps, self.thread_counts)
            self.axes[1, 1].set_ylim(0, max(1, max(self.thread_counts[-10:] or [0]) * 1.2))
            
            # Update x-axis for all plots if we have at least 2 points
            if len(self.timestamps) > 1:
                x_min = max(0, self.timestamps[0])
                x_max = self.timestamps[-1]
                
                # Add small buffer to prevent identical xlims
                if x_min == x_max:
                    x_max += 0.1
                
                for ax in self.axes.flat:
                    ax.set_xlim(x_min, x_max)
        
        return [self.cpu_line, self.memory_percent_line, self.memory_mb_line, self.thread_line]
    
    def start_monitoring(self):
        self.start_time = time.time()
        
        # Start animation
        self.ani = FuncAnimation(
            self.fig, 
            self.update_plots, 
            interval=1000, 
            blit=True,
            cache_frame_data=False,
            save_count=self.max_points
        )
        plt.show()

if __name__ == "__main__":
    try:
        print("=== Process Resource Monitor ===")
        monitor = ProcessMonitor()
        monitor.start_monitoring()
    except ValueError as e:
        print(f"Error: {e}")
    except KeyboardInterrupt:
        print("\nMonitoring stopped by user.")
    except Exception as e:
        print(f"An error occurred: {e}")
