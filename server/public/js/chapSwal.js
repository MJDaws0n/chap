// SweetAlert2 custom theme for Chap
window.chapSwal = function(options) {
  return Swal.fire({
    position: 'center',
    toast: false,
    background: '#23272f',
    color: '#e5e7eb',
    customClass: {
      popup: 'rounded-xl border border-gray-700 shadow-lg',
      title: 'text-lg font-bold text-white',
      confirmButton: 'bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500',
      cancelButton: 'bg-gray-700 hover:bg-gray-600 text-gray-300 px-6 py-2 rounded-lg ml-2 focus:outline-none focus:ring-2 focus:ring-gray-500',
      actions: 'flex justify-end space-x-2 mt-6',
      content: 'text-gray-300',
    },
    buttonsStyling: false,
    confirmButtonText: options.confirmButtonText || 'Confirm',
    cancelButtonText: options.cancelButtonText || 'Cancel',
    showCancelButton: options.showCancelButton || false,
    icon: options.icon || undefined,
    ...options
  });
};
