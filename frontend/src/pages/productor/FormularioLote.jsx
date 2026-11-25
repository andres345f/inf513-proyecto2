import MensajeCampo from '../../components/ui/MensajeCampo'
import { cantidadConUnidad } from '../../utils/etiquetas'
import { formatearMoneda } from '../../utils/formato'
import { atributosError } from '../../utils/validaciones'
import './Productor.css'

/**
 * Campos del formulario de lote y oferta (alta y edición, solo en el detalle de la producción).
 * El <form noValidate> vive en la página; la validación es propia (reglasLote en reglasProductor.js).
 *
 * - valores / alCambiar: estado controlado del formulario.
 * - errores: { campo: mensaje } del cliente o de la API; se muestran bajo cada campo.
 * - alCorregir(campo): se llama al editar un campo con error para quitar su mensaje.
 * - unidad: unidad de medida de la producción (para los textos de ayuda).
 * - porUnidad: la cantidad se captura en unidades enteras (no fraccionable).
 * - comprometido: cantidad ya comprometida con FEDER; el mínimo de «cantidad_ofrecida».
 */
export default function FormularioLote({ valores, alCambiar, errores = {}, alCorregir, unidad, porUnidad, comprometido, origenBloqueado = false, id = 'form-lote' }) {
  const paso = porUnidad ? '1' : '0.001'
  const cambiar = (campo, valor) => {
    alCambiar((previo) => ({ ...previo, [campo]: valor }))
    if (errores[campo]) alCorregir?.(campo)
  }
  const idError = (campo) => `${id}-${campo}-error`
  const accesible = (campo) => atributosError(errores, campo, idError(campo))

  return (
    <fieldset className="productor__rejilla" id={id}>
      {origenBloqueado && <p>FEDER ya registró una compra de este lote. Puedes ajustar la oferta y su precio.</p>}
      <label htmlFor={`${id}-elaboracion`}>Fecha de elaboración
        <input
          id={`${id}-elaboracion`}
          disabled={origenBloqueado}
          type="date"
          aria-required={Boolean(valores.fecha_vencimiento)}
          value={valores.fecha_elaboracion}
          onChange={(e) => cambiar('fecha_elaboracion', e.target.value)}
          {...accesible('fecha_elaboracion')}
        />
        <MensajeCampo id={idError('fecha_elaboracion')} error={errores.fecha_elaboracion} />
      </label>
      <label htmlFor={`${id}-vencimiento`}>Fecha de vencimiento
        <input
          id={`${id}-vencimiento`}
          disabled={origenBloqueado}
          type="date"
          min={valores.fecha_elaboracion || undefined}
          value={valores.fecha_vencimiento}
          onChange={(e) => cambiar('fecha_vencimiento', e.target.value)}
          {...accesible('fecha_vencimiento')}
        />
        <MensajeCampo id={idError('fecha_vencimiento')} error={errores.fecha_vencimiento} />
      </label>
      <label htmlFor={`${id}-producida`}>Cantidad producida
        <input
          id={`${id}-producida`}
          disabled={origenBloqueado}
          type="number"
          aria-required="true"
          min={porUnidad ? '1' : '0.001'}
          step={paso}
          inputMode="decimal"
          value={valores.cantidad_producida}
          onChange={(e) => cambiar('cantidad_producida', e.target.value)}
          {...accesible('cantidad_producida')}
        />
        <MensajeCampo id={idError('cantidad_producida')} error={errores.cantidad_producida} />
      </label>
      <label htmlFor={`${id}-ofrecida`}>Cantidad ofrecida a FEDER
        <input
          id={`${id}-ofrecida`}
          type="number"
          aria-required="true"
          min={String(comprometido)}
          step={paso}
          inputMode="decimal"
          value={valores.cantidad_ofrecida}
          onChange={(e) => cambiar('cantidad_ofrecida', e.target.value)}
          {...accesible('cantidad_ofrecida')}
        />
        <MensajeCampo id={idError('cantidad_ofrecida')} error={errores.cantidad_ofrecida} />
        {comprometido > 0 && (
          <small>Ya comprometido con FEDER: {cantidadConUnidad(comprometido, unidad)}.</small>
        )}
      </label>
      <label htmlFor={`${id}-precio`}>Precio sugerido por unidad
        <input
          id={`${id}-precio`}
          type="number"
          min="0"
          step="0.01"
          inputMode="decimal"
          value={valores.precio_ofrecido}
          onChange={(e) => cambiar('precio_ofrecido', e.target.value)}
          {...accesible('precio_ofrecido')}
        />
        <MensajeCampo id={idError('precio_ofrecido')} error={errores.precio_ofrecido} />
        {valores.precio_ofrecido !== '' && valores.precio_ofrecido != null && !errores.precio_ofrecido && (
          <small>Ofreces a {formatearMoneda(Number(valores.precio_ofrecido))} por {porUnidad ? `unidad de ${unidad.toLowerCase()}` : unidad.toLowerCase()}.</small>
        )}
      </label>
    </fieldset>
  )
}
