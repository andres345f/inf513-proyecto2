// Reglas de validación en el cliente de los formularios del productor.
// Reflejan ProduccionRequest y LoteProduccionRequest del backend, con mensajes propios en español.
import {
  fechaValida, longitudMaxima, longitudMinima, noAnteriorA, noMayorQueCampo, numero, requerido, requeridoSi,
} from '../../utils/validaciones.js' // con extensión: también lo importan las pruebas de Node

const MAXIMO_CANTIDAD = 999999999

function cantidadPositiva(porUnidad, nombre) {
  return porUnidad
    ? numero({ mayorQue: 0, maximo: MAXIMO_CANTIDAD, entero: true }, `${nombre} debe ser un número entero mayor que cero.`)
    : numero({ mayorQue: 0, maximo: MAXIMO_CANTIDAD, decimales: 3 }, `${nombre} debe ser mayor que cero, con hasta 3 decimales.`)
}

export function reglasProduccion(porUnidad) {
  return {
    producto_id: [requerido('Elige el producto que estás produciendo.')],
    fecha_inicio: [requerido('Indica la fecha de inicio.'), fechaValida('Ingresa una fecha de inicio válida.')],
    fecha_disponibilidad_estimada: [
      requerido('Indica la fecha estimada de disponibilidad.'),
      fechaValida('Ingresa una fecha de disponibilidad válida.'),
      noAnteriorA('fecha_inicio', 'La disponibilidad no puede ser anterior al inicio.'),
    ],
    cantidad_estimada: [requerido('Indica la cantidad estimada.'), cantidadPositiva(porUnidad, 'La cantidad estimada')],
    ubicacion_produccion: [
      requerido('Indica dónde se realiza la producción.'),
      longitudMinima(5, 'Describe la ubicación con al menos 5 caracteres.'),
      longitudMaxima(200, 'La ubicación'),
    ],
    observacion: [longitudMaxima(1000, 'Las observaciones')],
  }
}

export function reglasLote(porUnidad, comprometido = 0) {
  const minimoOferta = Number(comprometido) || 0
  const mensajeOferta = minimoOferta > 0
    ? `Debes ofrecer al menos lo ya comprometido con FEDER (${minimoOferta}).`
    : 'La cantidad ofrecida debe ser cero o más.'
  return {
    fecha_elaboracion: [
      requeridoSi((v) => Boolean(v.fecha_vencimiento), 'Indica la fecha de elaboración si registras una fecha de vencimiento.'),
      fechaValida('Ingresa una fecha de elaboración válida.'),
    ],
    fecha_vencimiento: [
      fechaValida('Ingresa una fecha de vencimiento válida.'),
      noAnteriorA('fecha_elaboracion', 'El vencimiento no puede ser anterior a la elaboración.'),
    ],
    cantidad_producida: [requerido('Indica la cantidad producida.'), cantidadPositiva(porUnidad, 'La cantidad producida')],
    cantidad_ofrecida: [
      requerido('Indica la cantidad que ofreces a FEDER.'),
      porUnidad
        ? numero({ minimo: minimoOferta, maximo: MAXIMO_CANTIDAD, entero: true }, `${mensajeOferta} Usa un número entero.`)
        : numero({ minimo: minimoOferta, maximo: MAXIMO_CANTIDAD, decimales: 3 }, mensajeOferta),
      noMayorQueCampo('cantidad_producida', 'No puedes ofrecer más de lo producido.'),
    ],
    precio_ofrecido: [numero({ minimo: 0, maximo: 9999999, decimales: 2 }, 'Ingresa un precio válido: cero o más, con hasta 2 decimales.')],
  }
}

/** Producto elegido en el formulario de producción (o el original si fue retirado del catálogo). */
export function productoSeleccionado(valores, opciones = [], detalle = null) {
  return opciones.find((p) => String(p.id) === String(valores.producto_id))
    || (detalle ? { id: detalle.producto_id, nombre: detalle.producto, presentacion: detalle.presentacion, unidad_medida: detalle.unidad_medida } : null)
}
