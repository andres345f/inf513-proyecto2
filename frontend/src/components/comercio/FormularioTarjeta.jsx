import CampoTexto from '../ui/CampoTexto'
import './FormularioTarjeta.css'


/** Datos de una tarjeta simulada. La validación la hace el formulario que lo contiene. */
export default function FormularioTarjeta({ valores, errores = {}, alCambiar, conAlias = false }) {
  const cambiar = (evento) => alCambiar({ ...valores, [evento.target.name]: evento.target.value })

  return (
    <div className="formulario-tarjeta">
      <CampoTexto etiqueta="Número de tarjeta" nombre="numero" inputMode="numeric" autoComplete="cc-number" obligatorio
        value={valores.numero} onChange={cambiar} error={errores.numero} maxLength={23} placeholder="4242 4242 4242 4242" />
      <CampoTexto etiqueta="Titular" nombre="titular" autoComplete="cc-name" obligatorio
        value={valores.titular} onChange={cambiar} error={errores.titular} maxLength={80} />
      <div className="formulario-tarjeta__fila">
        <CampoTexto etiqueta="Mes" nombre="expira_mes" inputMode="numeric" autoComplete="cc-exp-month" obligatorio
          value={valores.expira_mes} onChange={cambiar} error={errores.expira_mes} maxLength={2} placeholder="12" />
        <CampoTexto etiqueta="Año" nombre="expira_anio" inputMode="numeric" autoComplete="cc-exp-year" obligatorio
          value={valores.expira_anio} onChange={cambiar} error={errores.expira_anio} maxLength={4} placeholder="2028" />
        <CampoTexto etiqueta="CVV" nombre="cvv" inputMode="numeric" autoComplete="cc-csc" obligatorio tipo="password"
          value={valores.cvv} onChange={cambiar} error={errores.cvv} maxLength={4} />
      </div>
      {conAlias && (
        <CampoTexto etiqueta="Alias (opcional)" nombre="alias" value={valores.alias} onChange={cambiar}
          error={errores.alias} maxLength={40} placeholder="Mi tarjeta personal" />
      )}
      <p className="formulario-tarjeta__nota">
        Pasarela simulada para la demostración: no se realizan cobros reales y nunca guardamos el número completo ni el CVV.
      </p>
    </div>
  )
}
