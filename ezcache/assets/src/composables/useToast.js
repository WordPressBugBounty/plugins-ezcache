import Swal from 'sweetalert2'

export function useToast() {
  const Toast = Swal.mixin({
    toast: true,
    position: window.ezcache?.is_rtl ? 'top-start' : 'top-end',
    showConfirmButton: false,
    timer: 3500,
    timerProgressBar: true,
    didOpen: (toast) => {
      toast.addEventListener('mouseenter', Swal.stopTimer)
      toast.addEventListener('mouseleave', Swal.resumeTimer)
    }
  })

  return {
    success: (msg) => Toast.fire({ icon: 'success', title: msg }),
    error:   (msg) => Toast.fire({ icon: 'error',   title: msg }),
    info:    (msg) => Toast.fire({ icon: 'info',    title: msg }),
    confirm: (msg, confirmText, cancelText) => Swal.fire({
      text: msg,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: confirmText || 'OK',
      cancelButtonText: cancelText || 'Cancel',
      confirmButtonColor: '#ffcc00',
      cancelButtonColor: '#6b7280',
      color: '#111',
    })
  }
}
