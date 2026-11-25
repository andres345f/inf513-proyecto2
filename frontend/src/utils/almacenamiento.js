// Preferencias persistentes. En modo privado puede fallar y la app debe seguir funcionando.

export function leer(clave, valorPorDefecto = null) {
  try {
    const valor = window.localStorage.getItem(clave)
    return valor === null ? valorPorDefecto : JSON.parse(valor)
  } catch {
    return valorPorDefecto
  }
}

export function guardar(clave, valor) {
  try {
    window.localStorage.setItem(clave, JSON.stringify(valor))
  } catch {
    // Sin almacenamiento disponible: se mantiene solo en memoria.
  }
}

export function borrar(clave) {
  try {
    window.localStorage.removeItem(clave)
  } catch {
    // Nada que borrar.
  }
}

// Datos sensibles de la sesión: se eliminan automáticamente al cerrar la pestaña o el navegador.
export function leerSesion(clave, valorPorDefecto = null) {
  try {
    const valor = window.sessionStorage.getItem(clave)
    return valor === null ? valorPorDefecto : JSON.parse(valor)
  } catch {
    return valorPorDefecto
  }
}

export function guardarSesion(clave, valor) {
  try {
    window.sessionStorage.setItem(clave, JSON.stringify(valor))
  } catch {
    // Sin almacenamiento disponible: la sesión permanece únicamente en memoria.
  }
}

export function borrarSesion(clave) {
  try {
    window.sessionStorage.removeItem(clave)
  } catch {
    // Nada que borrar.
  }
}
