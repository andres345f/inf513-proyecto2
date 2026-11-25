import MensajeCampo from '../../components/ui/MensajeCampo'
import { cantidadConUnidad } from '../../utils/etiquetas'
import { atributosError } from '../../utils/validaciones'
import { productoSeleccionado } from './reglasProductor'
import './Productor.css'

/**
 * Campos del formulario de producción (alta en la lista, edición en el detalle).
 * El <form noValidate> y sus botones viven en la página: este componente solo dibuja los campos.
 * La validación es propia (reglasProduccion en reglasProductor.js), no la del navegador.
 *
 * - valores / alCambiar: estado controlado del formulario.
 * - errores: { campo: mensaje } del cliente o de la API; se muestran bajo cada campo.
 * - alCorregir(campo): se llama al editar un campo con error para quitar su mensaje.
 * - opciones: productos activos para ofrecer ({ id, nombre, presentacion, unidad_medida }).
 * - detalle: cuando se edita una producción cuyo producto fue retirado del catálogo,
 *   se agrega su opción original y se obliga la unidad de esa producción.
 */
export default function FormularioProduccion({ valores, alCambiar, errores = {}, alCorregir, opciones = [], detalle = null, idDetalle = 'form-produccion' }) {
  const seleccionado = productoSeleccionado(valores, opciones, detalle)
  const porUnidad = seleccionado?.unidad_medida === 'UNIDAD'
  const paso = porUnidad ? '1' : '0.001'
  const idError = (campo) => `${idDetalle}-${campo}-error`
  const accesible = (campo) => atributosError(errores, campo, idError(campo))

  const cambiar = (campo, valor, transformar = null) => {
    alCambiar((previo) => ({ ...previo, [campo]: transformar ? transformar(valor) : valor }))
    if (errores[campo]) alCorregir?.(campo)
  }

  return (
    <fieldset className="productor__rejilla" id={idDetalle}>
      <label htmlFor={`${idDetalle}-producto`}>Producto
        <select
          id={`${idDetalle}-producto`}
          aria-required="true"
          value={valores.producto_id}
          onChange={(e) => cambiar('producto_id', e.target.value)}
          {...accesible('producto_id')}
        >
          <option value="">Selecciona…</option>
          {detalle && !opciones.some((p) => String(p.id) === String(detalle.producto_id)) && (
            <option value={detalle.producto_id}>{detalle.producto} · {detalle.presentacion} (retirado)</option>
          )}
          {opciones.map((p) => <option key={p.id} value={p.id}>{p.nombre} · {p.presentacion}</option>)}
        </select>
        <MensajeCampo id={idError('producto_id')} error={errores.producto_id} />
      </label>
      <label htmlFor={`${idDetalle}-inicio`}>Fecha de inicio
        <input
          id={`${idDetalle}-inicio`}
          type="date"
          aria-required="true"
          value={valores.fecha_inicio}
          onChange={(e) => cambiar('fecha_inicio', e.target.value)}
          {...accesible('fecha_inicio')}
        />
        <MensajeCampo id={idError('fecha_inicio')} error={errores.fecha_inicio} />
      </label>
      <label htmlFor={`${idDetalle}-disponible`}>Disponibilidad estimada
        <input
          id={`${idDetalle}-disponible`}
          type="date"
          min={valores.fecha_inicio || undefined}
          aria-required="true"
          value={valores.fecha_disponibilidad_estimada}
          onChange={(e) => cambiar('fecha_disponibilidad_estimada', e.target.value)}
          {...accesible('fecha_disponibilidad_estimada')}
        />
        <MensajeCampo id={idError('fecha_disponibilidad_estimada')} error={errores.fecha_disponibilidad_estimada} />
      </label>
      <label htmlFor={`${idDetalle}-cantidad`}>Cantidad estimada ({porUnidad ? 'unidades' : 'fraccionable'})
        <input
          id={`${idDetalle}-cantidad`}
          type="number"
          aria-required="true"
          min={porUnidad ? '1' : '0.001'}
          step={paso}
          inputMode="decimal"
          value={valores.cantidad_estimada}
          onChange={(e) => cambiar('cantidad_estimada', e.target.value)}
          {...accesible('cantidad_estimada')}
        />
        <MensajeCampo id={idError('cantidad_estimada')} error={errores.cantidad_estimada} />
      </label>
      <label htmlFor={`${idDetalle}-ubicacion`}>Ubicación de la producción
        <input
          id={`${idDetalle}-ubicacion`}
          aria-required="true"
          maxLength={200}
          value={valores.ubicacion_produccion}
          onChange={(e) => cambiar('ubicacion_produccion', e.target.value)}
          {...accesible('ubicacion_produccion')}
        />
        <MensajeCampo id={idError('ubicacion_produccion')} error={errores.ubicacion_produccion} />
      </label>
      <label htmlFor={`${idDetalle}-observacion`}>Observaciones
        <textarea
          id={`${idDetalle}-observacion`}
          className="productor__observacion"
          rows={2}
          maxLength={1000}
          value={valores.observacion}
          onChange={(e) => cambiar('observacion', e.target.value)}
          {...accesible('observacion')}
        />
        <MensajeCampo id={idError('observacion')} error={errores.observacion} />
      </label>
      {seleccionado && (
        <p className="productor__ayuda">
          Se producirá en {seleccionado.unidad_medida === 'UNIDAD' ? 'unidades enteras' : `fracciones de ${seleccionado.unidad_medida.toLowerCase()}`}
          {valores.cantidad_estimada ? ` · ${cantidadConUnidad(Number(valores.cantidad_estimada), seleccionado.unidad_medida)} estimadas` : ''}.
        </p>
      )}
    </fieldset>
  )
}
